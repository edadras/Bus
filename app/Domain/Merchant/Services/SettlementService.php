<?php

namespace App\Domain\Merchant\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Enums\SettlementStatus;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Merchant\Models\MerchantTransaction;
use App\Domain\Merchant\Models\Settlement;
use App\Domain\Wallet\Enums\TransactionStatus;
use App\Domain\Wallet\Services\WalletService;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paying merchants out.
 *
 * A settlement claims a fixed set of completed, unsettled transactions by
 * stamping them with its id inside a locked transaction. That claim is what
 * makes the process safe to run twice: a transaction can belong to exactly one
 * settlement, so a re-run finds nothing left to claim rather than paying twice.
 */
class SettlementService
{
    public function __construct(private readonly WalletService $wallets) {}

    public function draft(
        Merchant $merchant,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?User $requestedBy = null,
    ): Settlement {
        return DB::transaction(function () use ($merchant, $periodStart, $periodEnd, $requestedBy): Settlement {
            $settlement = Settlement::create([
                'merchant_id' => $merchant->id,
                'reference' => $this->generateReference(),
                'status' => SettlementStatus::Draft,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'currency' => config('wallet.currency'),
                'requested_by' => $requestedBy?->id,
                'requested_at' => $requestedBy !== null ? now() : null,
            ]);

            // Claim the rows. lockForUpdate serialises two concurrent drafts so
            // the second sees an empty set instead of double-claiming.
            $claimable = MerchantTransaction::query()
                ->where('merchant_id', $merchant->id)
                ->settleable()
                ->whereBetween('created_at', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()])
                ->lockForUpdate()
                ->get();

            if ($claimable->isEmpty()) {
                throw DomainException::make('nothing_to_settle', 422, [
                    'merchant' => $merchant->code,
                    'period' => $periodStart->toDateString().' → '.$periodEnd->toDateString(),
                ]);
            }

            MerchantTransaction::whereIn('id', $claimable->pluck('id'))
                ->update(['settlement_id' => $settlement->id]);

            $refunds = MerchantTransaction::query()
                ->where('merchant_id', $merchant->id)
                ->where('status', TransactionStatus::Reversed->value)
                ->whereBetween('refunded_at', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()])
                ->sum('amount');

            $gross = (int) $claimable->sum('amount');
            $commission = (int) $claimable->sum('commission_amount');

            $settlement->forceFill([
                'transaction_count' => $claimable->count(),
                'gross_amount' => $gross,
                'commission_amount' => $commission,
                'refund_amount' => (int) $refunds,
                'net_amount' => max(0, $gross - $commission - (int) $refunds),
                'status' => $requestedBy !== null ? SettlementStatus::Requested : SettlementStatus::Draft,
            ])->save();

            return $settlement->fresh();
        });
    }

    /**
     * Approve and post the payout. The merchant's wallet is debited to the
     * settlement payable account: the money leaves the platform's float at the
     * moment of approval, not when the bank transfer clears.
     */
    public function approve(Settlement $settlement, User $approver): Settlement
    {
        if (! in_array($settlement->status, [SettlementStatus::Draft, SettlementStatus::Requested], true)) {
            throw DomainException::make('settlement_not_approvable', 422, [
                'status' => $settlement->status->value,
            ]);
        }

        $minimum = (int) config('wallet.settlement.min_settlement_amount');

        if ($settlement->net_amount < $minimum) {
            throw DomainException::make('settlement_below_minimum', 422, ['minimum' => $minimum]);
        }

        return DB::transaction(function () use ($settlement, $approver): Settlement {
            $merchantWallet = $this->wallets->forMerchant($settlement->merchant);

            $transaction = $this->wallets->postSettlement(
                merchantWallet: $merchantWallet,
                amount: $settlement->net_amount,
                idempotencyKey: 'settlement:'.$settlement->uuid,
                subject: $settlement,
                initiatedBy: $approver,
            );

            $settlement->forceFill([
                'status' => SettlementStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'wallet_transaction_id' => $transaction->id,
            ])->save();

            return $settlement->fresh();
        });
    }

    /** Mark the bank transfer as executed. */
    public function markPaid(Settlement $settlement, string $paymentReference): Settlement
    {
        if ($settlement->status !== SettlementStatus::Approved) {
            throw DomainException::make('settlement_not_approved', 422);
        }

        $settlement->forceFill([
            'status' => SettlementStatus::Paid,
            'paid_at' => now(),
            'payment_reference' => $paymentReference,
        ])->save();

        return $settlement;
    }

    /** Reject and release the claimed transactions back into the pool. */
    public function reject(Settlement $settlement, User $actor, string $reason): Settlement
    {
        if ($settlement->status === SettlementStatus::Paid) {
            throw DomainException::make('settlement_already_paid', 422);
        }

        return DB::transaction(function () use ($settlement, $actor, $reason): Settlement {
            MerchantTransaction::where('settlement_id', $settlement->id)
                ->update(['settlement_id' => null]);

            $settlement->forceFill([
                'status' => SettlementStatus::Rejected,
                'rejection_reason' => $reason,
                'approved_by' => $actor->id,
            ])->save();

            return $settlement;
        });
    }

    /** Unsettled balance available to a merchant right now. */
    public function pendingBalance(Merchant $merchant): array
    {
        $rows = MerchantTransaction::query()
            ->where('merchant_id', $merchant->id)
            ->settleable()
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount),0) as gross, COALESCE(SUM(commission_amount),0) as commission')
            ->first();

        $gross = (int) $rows->gross;
        $commission = (int) $rows->commission;

        return [
            'transaction_count' => (int) $rows->cnt,
            'gross_amount' => $gross,
            'commission_amount' => $commission,
            'net_amount' => $gross - $commission,
        ];
    }

    private function generateReference(): string
    {
        do {
            $reference = 'STL'.now()->format('ymd').Str::upper(Str::random(6));
        } while (Settlement::where('reference', $reference)->exists());

        return $reference;
    }
}
