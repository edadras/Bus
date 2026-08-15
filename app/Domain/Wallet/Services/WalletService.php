<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Wallet\DTO\PostingLine;
use App\Domain\Wallet\DTO\PostingRequest;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Enums\WalletOwnerType;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Exceptions\DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Business-facing wallet operations. Everything here delegates the actual
 * money movement to LedgerService; this class owns the vocabulary (top-up,
 * fare, merchant charge) and the account wiring for each flow.
 */
class WalletService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly SystemAccountRegistry $system,
    ) {}

    public function forUser(User $user): Wallet
    {
        return Wallet::firstOrCreate(
            [
                'owner_type' => WalletOwnerType::User->value,
                'owner_id' => $user->id,
                'currency' => config('wallet.currency'),
            ],
            [
                'city_id' => $user->city_id,
                'balance' => 0,
                'status' => WalletStatus::Active,
            ],
        );
    }

    public function forMerchant(Merchant $merchant): Wallet
    {
        return Wallet::firstOrCreate(
            [
                'owner_type' => WalletOwnerType::Merchant->value,
                'owner_id' => $merchant->id,
                'currency' => config('wallet.currency'),
            ],
            [
                'city_id' => $merchant->city_id,
                'balance' => 0,
                'status' => WalletStatus::Active,
            ],
        );
    }

    /**
     * Credit a wallet against the gateway clearing account. Called only after
     * the gateway has confirmed settlement, never on the redirect alone.
     */
    public function creditTopup(
        Wallet $wallet,
        int $amount,
        string $idempotencyKey,
        ?\Illuminate\Database\Eloquent\Model $subject = null,
        array $metadata = [],
    ): WalletTransaction {
        $this->assertTopupWithinLimits($wallet, $amount);

        return $this->ledger->post(new PostingRequest(
            type: TransactionType::Topup,
            lines: [
                PostingLine::debit($this->system->gatewayClearing(), $amount),
                PostingLine::credit($wallet, $amount),
            ],
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            subject: $subject,
            cityId: $wallet->city_id,
            description: __('wallet.topup_description'),
            metadata: $metadata,
            bypassSpendLimit: true,
        ));
    }

    /** Passenger wallet -> fare revenue. */
    public function chargeFare(
        Wallet $wallet,
        int $amount,
        string $idempotencyKey,
        \Illuminate\Database\Eloquent\Model $subject,
        ?User $initiatedBy = null,
        array $metadata = [],
    ): WalletTransaction {
        return $this->ledger->post(new PostingRequest(
            type: TransactionType::FarePayment,
            lines: [
                PostingLine::debit($wallet, $amount),
                PostingLine::credit($this->system->fareRevenue(), $amount),
            ],
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            initiatedBy: $initiatedBy,
            subject: $subject,
            cityId: $wallet->city_id,
            description: __('wallet.fare_description'),
            metadata: $metadata,
        ));
    }

    /**
     * Passenger wallet -> merchant wallet, with the platform's commission
     * split out in the same posting so the merchant's balance is always the
     * net settleable figure and never needs a later correction.
     */
    public function chargeMerchant(
        Wallet $payer,
        Wallet $merchantWallet,
        int $amount,
        int $commission,
        string $idempotencyKey,
        \Illuminate\Database\Eloquent\Model $subject,
        ?User $initiatedBy = null,
        array $metadata = [],
    ): WalletTransaction {
        if ($commission < 0 || $commission > $amount) {
            throw DomainException::make('invalid_commission', 500, compact('amount', 'commission'));
        }

        $net = $amount - $commission;

        $lines = [PostingLine::debit($payer, $amount)];

        if ($net > 0) {
            $lines[] = PostingLine::credit($merchantWallet, $net);
        }

        if ($commission > 0) {
            $lines[] = PostingLine::credit($this->system->commissionRevenue(), $commission);
        }

        return $this->ledger->post(new PostingRequest(
            type: TransactionType::MerchantPayment,
            lines: $lines,
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            initiatedBy: $initiatedBy,
            subject: $subject,
            cityId: $payer->city_id,
            description: __('wallet.merchant_payment_description'),
            metadata: $metadata,
        ));
    }

    /** Merchant wallet -> settlement payable, when a payout is approved. */
    public function postSettlement(
        Wallet $merchantWallet,
        int $amount,
        string $idempotencyKey,
        \Illuminate\Database\Eloquent\Model $subject,
        ?User $initiatedBy = null,
    ): WalletTransaction {
        return $this->ledger->post(new PostingRequest(
            type: TransactionType::Settlement,
            lines: [
                PostingLine::debit($merchantWallet, $amount),
                PostingLine::credit($this->system->settlementPayable(), $amount),
            ],
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            initiatedBy: $initiatedBy,
            subject: $subject,
            cityId: $merchantWallet->city_id,
            description: __('wallet.settlement_description'),
            bypassSpendLimit: true,
        ));
    }

    /** Manual correction by finance staff; always leaves an audit trail. */
    public function adjust(
        Wallet $wallet,
        int $amount,
        string $reason,
        User $initiatedBy,
        string $idempotencyKey,
    ): WalletTransaction {
        $adjustments = $this->system->adjustments();

        $lines = $amount > 0
            ? [PostingLine::debit($adjustments, $amount), PostingLine::credit($wallet, $amount)]
            : [PostingLine::debit($wallet, abs($amount)), PostingLine::credit($adjustments, abs($amount))];

        return $this->ledger->post(new PostingRequest(
            type: TransactionType::Adjustment,
            lines: $lines,
            amount: abs($amount),
            idempotencyKey: $idempotencyKey,
            initiatedBy: $initiatedBy,
            cityId: $wallet->city_id,
            description: $reason,
            metadata: ['reason' => $reason],
            bypassSpendLimit: true,
        ));
    }

    public function refund(
        WalletTransaction $original,
        string $reason,
        ?User $initiatedBy = null,
    ): WalletTransaction {
        return $this->ledger->reverse($original, $reason);
    }

    public function freeze(Wallet $wallet, string $reason): void
    {
        $wallet->forceFill([
            'status' => WalletStatus::Frozen,
            'frozen_at' => now(),
            'frozen_reason' => $reason,
        ])->save();
    }

    public function unfreeze(Wallet $wallet): void
    {
        $wallet->forceFill([
            'status' => WalletStatus::Active,
            'frozen_at' => null,
            'frozen_reason' => null,
        ])->save();
    }

    /** Statement rows for a wallet, newest first. */
    public function statement(Wallet $wallet, int $perPage = 20, ?string $type = null): LengthAwarePaginator
    {
        return $wallet->ledgerEntries()
            ->with(['transaction' => fn ($q) => $q->select(
                'id', 'uuid', 'type', 'status', 'amount', 'description', 'subject_type', 'subject_id', 'created_at'
            )])
            ->when($type !== null, fn (Builder $q) => $q->whereHas(
                'transaction',
                fn (Builder $t) => $t->where('type', $type)
            ))
            ->orderByDesc('sequence')
            ->paginate($perPage);
    }

    private function assertTopupWithinLimits(Wallet $wallet, int $amount): void
    {
        $min = (int) config('wallet.limits.min_topup');
        $max = (int) config('wallet.limits.max_topup');
        $maxBalance = (int) config('wallet.limits.max_balance');

        if ($amount < $min) {
            throw DomainException::make('topup_below_minimum', 422, ['minimum' => $min]);
        }

        if ($amount > $max) {
            throw DomainException::make('topup_above_maximum', 422, ['maximum' => $max]);
        }

        if ($maxBalance > 0 && $wallet->balance + $amount > $maxBalance) {
            throw DomainException::make('wallet_balance_limit_exceeded', 422, [
                'maximum' => $maxBalance,
                'balance' => $wallet->balance,
            ]);
        }
    }
}
