<?php

namespace App\Domain\SchoolTransport\Services;

use App\Domain\Identity\Models\User;
use App\Domain\SchoolTransport\Enums\SchoolContractStatus;
use App\Domain\SchoolTransport\Enums\SchoolServiceDirection;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Domain\SchoolTransport\Models\SchoolContractInvoice;
use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Domain\SchoolTransport\Models\SchoolServiceRoute;
use App\Domain\SchoolTransport\Models\SchoolStudent;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The agreement between a family and a company.
 *
 * Four states matter and they are separate on purpose: a parent *requests*, a
 * company *accepts*, the company *places* the child on a route, and only then
 * is the contract *active*. Accepting and placing are different decisions taken
 * at different times — a company will take a family in August and work out the
 * vans in September — and collapsing them would either force the company to
 * plan before it can say yes, or let a child be "active" with no seat.
 */
class SchoolContractService
{
    public function __construct(private readonly SchoolInvoiceService $invoices) {}

    /** A parent asks a company to carry their child. */
    public function request(
        SchoolStudent $student,
        SchoolCompany $company,
        User $guardian,
        array $attributes,
    ): SchoolServiceContract {
        if ($student->guardian_user_id !== $guardian->id) {
            throw DomainException::make('not_your_student', 403);
        }

        if (! $company->status->isVisibleToGuardians()) {
            throw DomainException::make('company_not_approved', 422, ['company' => $company->name]);
        }

        if ($company->city_id !== $student->city_id) {
            throw DomainException::make('city_mismatch', 422);
        }

        // A child registered without a school. The contract is an arrangement
        // to carry them *somewhere*, and every route serves one school, so
        // there is nothing to agree to yet. Refused with a message the parent
        // can act on rather than a constraint violation from the database.
        if ($student->school_id === null) {
            throw DomainException::make('student_has_no_school', 422, [
                'student' => $student->name,
            ]);
        }

        $existing = SchoolServiceContract::query()
            ->where('school_student_id', $student->id)
            ->whereIn('status', [
                SchoolContractStatus::Requested->value,
                SchoolContractStatus::Approved->value,
                SchoolContractStatus::Active->value,
            ])
            ->first();

        if ($existing !== null) {
            // One child cannot be on two vans. Left as a refusal rather than a
            // silent replacement: ending the old arrangement is the parent's
            // decision, and it may already have been paid for.
            throw DomainException::make('student_already_contracted', 409, [
                'contract_uuid' => $existing->uuid,
            ]);
        }

        // Refreshed before it is returned: `create()` hands back a model that
        // knows only what was written, so every column the database defaults —
        // the fee, the discount — would reach the parent's first response as a
        // null it then has to guess about.
        return tap(SchoolServiceContract::create([
            'reference' => $this->generateReference(),
            'school_student_id' => $student->id,
            'guardian_user_id' => $guardian->id,
            'school_company_id' => $company->id,
            'school_id' => $student->school_id,
            'city_id' => $student->city_id,
            'status' => SchoolContractStatus::Requested,
            'direction' => $attributes['direction'] ?? SchoolServiceDirection::Both->value,
            'starts_on' => $attributes['starts_on'] ?? today(),
            'ends_on' => $attributes['ends_on'] ?? null,
            'days_of_week' => $attributes['days_of_week'] ?? null,
            'pickup_address' => $attributes['pickup_address'] ?? $student->pickup_address,
            'pickup_lat' => $attributes['pickup_lat'] ?? $student->pickup_lat,
            'pickup_lng' => $attributes['pickup_lng'] ?? $student->pickup_lng,
            'guardian_note' => $attributes['guardian_note'] ?? null,
        ]), fn (SchoolServiceContract $contract) => $contract->refresh());
    }

    /** The company says yes, and names the fee. */
    public function accept(
        SchoolServiceContract $contract,
        User $actor,
        int $feeAmount,
        string $paymentCycle = 'monthly',
        ?string $note = null,
    ): SchoolServiceContract {
        if ($contract->status !== SchoolContractStatus::Requested) {
            throw DomainException::make('contract_not_pending', 422, [
                'status' => $contract->status->value,
            ]);
        }

        if ($feeAmount < 0) {
            throw DomainException::make('amount_must_be_positive', 422);
        }

        $contract->forceFill([
            'status' => SchoolContractStatus::Approved,
            'fee_amount' => $feeAmount,
            'payment_cycle' => $paymentCycle,
            'company_note' => $note,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        return $contract->fresh();
    }

    public function reject(SchoolServiceContract $contract, User $actor, string $reason): SchoolServiceContract
    {
        if ($contract->status->isLive()) {
            throw DomainException::make('contract_already_active', 422);
        }

        $contract->forceFill([
            'status' => SchoolContractStatus::Rejected,
            'rejection_reason' => $reason,
            'approved_by' => $actor->id,
        ])->save();

        return $contract->fresh();
    }

    /**
     * Put the child on a van.
     *
     * This is the step that turns an agreement into a seat, so it is also the
     * one that has to check there is a seat: a route carrying more children
     * than it has belts is not an administrative detail.
     */
    public function assignRoute(
        SchoolServiceContract $contract,
        SchoolServiceRoute $route,
        User $actor,
    ): SchoolServiceContract {
        if (! in_array($contract->status, [SchoolContractStatus::Approved, SchoolContractStatus::Active], true)) {
            throw DomainException::make('contract_not_assignable', 422, [
                'status' => $contract->status->value,
            ]);
        }

        if ($route->school_company_id !== $contract->school_company_id) {
            throw DomainException::make('route_belongs_to_another_company', 422);
        }

        if ($route->school_id !== $contract->school_id) {
            throw DomainException::make('route_serves_another_school', 422);
        }

        return DB::transaction(function () use ($contract, $route, $actor): SchoolServiceContract {
            $locked = SchoolServiceRoute::whereKey($route->id)->lockForUpdate()->firstOrFail();

            $taken = $locked->contracts()
                ->where('status', SchoolContractStatus::Active->value)
                ->where('id', '!=', $contract->id)
                ->count();

            if ($taken >= $locked->capacity) {
                throw DomainException::make('route_is_full', 409, [
                    'capacity' => $locked->capacity,
                    'taken' => $taken,
                ]);
            }

            $contract->forceFill([
                'school_service_route_id' => $locked->id,
                'status' => SchoolContractStatus::Active,
                'activated_at' => $contract->activated_at ?? now(),
                'approved_by' => $contract->approved_by ?? $actor->id,
            ])->save();

            $contract->company?->increment('contract_count');

            return $contract->fresh();
        });
    }

    /** Take the child off the route without ending the agreement. */
    public function suspend(SchoolServiceContract $contract, User $actor, string $reason): SchoolServiceContract
    {
        $contract->forceFill([
            'status' => SchoolContractStatus::Suspended,
            'company_note' => $reason,
        ])->save();

        return $contract->fresh();
    }

    public function resume(SchoolServiceContract $contract): SchoolServiceContract
    {
        if ($contract->status !== SchoolContractStatus::Suspended) {
            return $contract;
        }

        $contract->forceFill([
            'status' => $contract->school_service_route_id === null
                ? SchoolContractStatus::Approved
                : SchoolContractStatus::Active,
        ])->save();

        return $contract->fresh();
    }

    /**
     * End the arrangement.
     *
     * Unpaid invoices are left standing: a family that leaves owing two months
     * still owes two months, and cancelling the debt with the contract would
     * make leaving the cheapest way to not pay.
     */
    public function end(SchoolServiceContract $contract, User $actor, ?string $reason = null): SchoolServiceContract
    {
        $contract->forceFill([
            'status' => SchoolContractStatus::Ended,
            'ended_at' => now(),
            'ends_on' => $contract->ends_on ?? today(),
            'company_note' => $reason ?? $contract->company_note,
        ])->save();

        return $contract->fresh();
    }

    /** Issue this period's fee, which is what makes the contract billable. */
    public function billCurrentPeriod(SchoolServiceContract $contract): ?SchoolContractInvoice
    {
        if (! $contract->status->isLive() || $contract->payableAmount() <= 0) {
            return null;
        }

        return $this->invoices->issueFor($contract);
    }

    private function generateReference(): string
    {
        $prefix = (string) config('school.contracts.reference_prefix', 'SC');

        do {
            $candidate = $prefix.'-'.now()->format('y').'-'.Str::upper(Str::random(6));
        } while (SchoolServiceContract::where('reference', $candidate)->exists());

        return $candidate;
    }
}
