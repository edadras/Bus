<?php

namespace App\Domain\SchoolTransport\Services;

use App\Domain\Identity\Models\User;
use App\Domain\SchoolTransport\Enums\SchoolInvoiceStatus;
use App\Domain\SchoolTransport\Models\SchoolContractInvoice;
use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Domain\Wallet\DTO\PostingLine;
use App\Domain\Wallet\DTO\PostingRequest;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Services\LedgerService;
use App\Domain\Wallet\Services\SystemAccountRegistry;
use App\Domain\Wallet\Services\WalletService;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Billing a school service contract.
 *
 * The fee goes from the guardian's wallet to the company owner's wallet with
 * the platform's cut split out in the same posting, which is the shape every
 * other payment on the platform already has. Nothing is ever swept
 * automatically: a parent pays an invoice because they chose to, and a balance
 * silently taken the moment it arrives is how people stop trusting a wallet.
 */
class SchoolInvoiceService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly LedgerService $ledger,
        private readonly SystemAccountRegistry $system,
    ) {}

    /**
     * Issue the invoice for a period, or return the one already issued.
     *
     * Idempotent on the period so a scheduler that runs twice — or a manual
     * click after an automatic run — cannot bill a family twice for one month.
     */
    public function issueFor(
        SchoolServiceContract $contract,
        ?CarbonInterface $periodStart = null,
    ): SchoolContractInvoice {
        $start = ($periodStart ?? now())->copy()->startOfDay();
        [$from, $to] = $this->periodFor($contract, $start);

        $existing = $contract->invoices()
            ->whereDate('period_start', $from->toDateString())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return tap($contract->invoices()->create([
            'city_id' => $contract->city_id,
            'reference' => $this->generateReference(),
            'status' => SchoolInvoiceStatus::Pending,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            // Counted from the day the invoice is issued, not from the start
            // of the period it covers: a family first billed on the 24th of a
            // month would otherwise be overdue the moment the invoice existed.
            // A deliberate backfill passes its own anchor and does land in the
            // past, which is the point of a backfill.
            'due_on' => $start->copy()->addDays((int) config('school.invoices.due_days', 7))->toDateString(),
            'amount' => $contract->payableAmount(),
            'commission_amount' => intdiv(
                $contract->payableAmount() * ($contract->company?->commissionBps() ?? 0),
                10_000,
            ),
        ]), fn (SchoolContractInvoice $invoice) => $invoice->refresh());
    }

    /** The guardian pays. */
    public function pay(SchoolContractInvoice $invoice, User $payer): SchoolContractInvoice
    {
        if (! $invoice->status->isPayable()) {
            throw DomainException::make('invoice_not_payable', 422, [
                'status' => $invoice->status->value,
            ]);
        }

        $contract = $invoice->contract;

        if ($contract === null || $contract->guardian_user_id !== $payer->id) {
            throw DomainException::make('not_your_invoice', 403);
        }

        $companyOwner = $contract->company?->owner;

        if ($companyOwner === null) {
            throw DomainException::make('company_has_no_account', 500);
        }

        return DB::transaction(function () use ($invoice, $payer, $companyOwner): SchoolContractInvoice {
            $payerWallet = $this->wallets->forUser($payer);
            $companyWallet = $this->wallets->forUser($companyOwner);

            $amount = (int) $invoice->amount;
            $commission = (int) $invoice->commission_amount;
            $net = $amount - $commission;

            $lines = [PostingLine::debit($payerWallet, $amount)];

            if ($net > 0) {
                $lines[] = PostingLine::credit($companyWallet, $net);
            }

            if ($commission > 0) {
                $lines[] = PostingLine::credit($this->system->commissionRevenue(), $commission);
            }

            $transaction = $this->ledger->post(new PostingRequest(
                type: TransactionType::SchoolFee,
                lines: $lines,
                amount: $amount,
                // Idempotent on the invoice: a retried payment returns the
                // original posting rather than billing the month again.
                idempotencyKey: 'school-invoice:'.$invoice->uuid,
                initiatedBy: $payer,
                subject: $invoice,
                cityId: $invoice->city_id,
                description: __('wallet.school_fee_description'),
                metadata: [
                    'contract_reference' => $invoice->contract?->reference,
                    'period' => $invoice->period_start?->toDateString(),
                ],
            ));

            $invoice->forceFill([
                'status' => SchoolInvoiceStatus::Paid,
                'paid_at' => now(),
                'wallet_transaction_id' => $transaction->id,
            ])->save();

            return $invoice->fresh();
        });
    }

    /** Mark everything past its due date, so "overdue" is a fact not a query. */
    public function markOverdue(): int
    {
        return SchoolContractInvoice::query()
            ->where('status', SchoolInvoiceStatus::Pending->value)
            ->whereDate('due_on', '<', today())
            ->update(['status' => SchoolInvoiceStatus::Overdue->value]);
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function periodFor(SchoolServiceContract $contract, CarbonInterface $anchor): array
    {
        return match ($contract->payment_cycle) {
            'termly' => [$anchor->copy()->startOfQuarter(), $anchor->copy()->endOfQuarter()],
            'yearly' => [$anchor->copy()->startOfYear(), $anchor->copy()->endOfYear()],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
        };
    }

    private function generateReference(): string
    {
        $prefix = (string) config('school.invoices.reference_prefix', 'SCH');

        do {
            $candidate = $prefix.'-'.now()->format('ym').'-'.Str::upper(Str::random(6));
        } while (SchoolContractInvoice::where('reference', $candidate)->exists());

        return $candidate;
    }
}
