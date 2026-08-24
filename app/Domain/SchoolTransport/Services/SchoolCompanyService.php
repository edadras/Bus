<?php

namespace App\Domain\SchoolTransport\Services;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\SchoolTransport\Enums\SchoolCompanyStatus;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vetting the companies that carry children.
 *
 * Approval is the product here, not paperwork: until an administrator has said
 * yes, a company is invisible to every parent, cannot be contracted with, and
 * cannot put a van on the road. Suspension takes all three away again with one
 * decision, which is what makes it usable in the moment it is needed.
 */
class SchoolCompanyService
{
    public function register(array $attributes, User $owner, int $cityId): SchoolCompany
    {
        return DB::transaction(function () use ($attributes, $owner, $cityId): SchoolCompany {
            $company = SchoolCompany::create($attributes + [
                'city_id' => $cityId,
                'owner_user_id' => $owner->id,
                'code' => $this->generateCode($attributes['name'] ?? 'SCH'),
                // Explicitly not active: a company that registered itself into
                // a parent's list of options would defeat the whole point.
                'status' => SchoolCompanyStatus::PendingApproval,
            ]);

            $company->staff()->create([
                'user_id' => $owner->id,
                'role' => 'manager',
                'is_active' => true,
            ]);

            $owner->assignRole(Role::SCHOOL_COMPANY_MANAGER, $cityId);

            return $company;
        });
    }

    public function approve(SchoolCompany $company, User $approver): SchoolCompany
    {
        if ($company->status === SchoolCompanyStatus::Active) {
            return $company;
        }

        $company->forceFill([
            'status' => SchoolCompanyStatus::Active,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();

        return $company->fresh();
    }

    public function reject(SchoolCompany $company, User $actor, string $reason): SchoolCompany
    {
        $company->forceFill([
            'status' => SchoolCompanyStatus::Rejected,
            'approved_by' => $actor->id,
            'rejection_reason' => $reason,
        ])->save();

        return $company->fresh();
    }

    /**
     * Suspend, and stop the vans.
     *
     * Contracts are left alone deliberately: a suspension is usually temporary
     * and cancelling a hundred families' places would be a far larger action
     * than the one being taken. What stops is the running — the route readiness
     * check reads the company's status, so no new run starts.
     */
    public function suspend(SchoolCompany $company, User $actor, string $reason): SchoolCompany
    {
        $company->forceFill([
            'status' => SchoolCompanyStatus::Suspended,
            'rejection_reason' => $reason,
        ])->save();

        $company->routes()->update(['is_active' => false]);

        return $company->fresh();
    }

    public function addStaff(SchoolCompany $company, User $user, string $role = 'dispatcher'): void
    {
        if (! in_array($role, ['manager', 'dispatcher'], true)) {
            throw DomainException::make('invalid_role', 422, ['role' => $role]);
        }

        $company->staff()->updateOrCreate(
            ['user_id' => $user->id],
            ['role' => $role, 'is_active' => true],
        );

        $user->assignRole(
            $role === 'manager' ? Role::SCHOOL_COMPANY_MANAGER : Role::SCHOOL_COMPANY_STAFF,
            $company->city_id,
        );
    }

    /** The companies a user may act for, which is how the panel scopes itself. */
    public function companiesFor(User $user): array
    {
        return SchoolCompany::query()
            ->whereHas('staff', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true))
            ->orWhere('owner_user_id', $user->id)
            ->pluck('id')
            ->all();
    }

    private function generateCode(string $name): string
    {
        $base = Str::upper(Str::substr(Str::slug($name, ''), 0, 4)) ?: 'SCH';

        do {
            $code = $base.Str::upper(Str::random(5));
        } while (SchoolCompany::where('code', $code)->exists());

        return $code;
    }
}
