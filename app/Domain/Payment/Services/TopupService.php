<?php

namespace App\Domain\Payment\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Wallet\Models\Payment;
use App\Domain\Wallet\Services\WalletService;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Wallet top-up orchestration.
 *
 * The invariant that matters: a payment credits the wallet exactly once. That
 * is enforced in two places — the payment's own status transition (only a
 * non-final payment can be verified) and the ledger's idempotency key derived
 * from the payment uuid. Either alone would be enough; together they make a
 * duplicated callback a no-op rather than free money.
 */
class TopupService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly WalletService $wallets,
    ) {}

    public function initiate(User $user, int $amount, ?string $gateway = null, ?string $returnUrl = null): array
    {
        $min = (int) config('wallet.limits.min_topup');
        $max = (int) config('wallet.limits.max_topup');

        if ($amount < $min) {
            throw DomainException::make('topup_below_minimum', 422, ['minimum' => $min]);
        }

        if ($amount > $max) {
            throw DomainException::make('topup_above_maximum', 422, ['maximum' => $max]);
        }

        $driver = $this->gateways->driver($gateway);
        $wallet = $this->wallets->forUser($user);

        $payment = Payment::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'gateway' => $driver->name(),
            'status' => PaymentStatus::Initiated,
            'amount' => $amount,
            'currency' => config('wallet.currency'),
            'return_url' => $returnUrl,
            'client_ip' => request()->ip(),
        ]);

        $redirect = $driver->initiate($payment);

        $payment->forceFill(['status' => PaymentStatus::Pending])->save();

        return ['payment' => $payment->fresh(), 'redirect' => $redirect];
    }

    /** Verify a gateway callback and, on success, credit the wallet. */
    public function verify(Payment $payment, array $callback): Payment
    {
        if ($payment->status->isFinal()) {
            // A duplicated callback. Return the settled payment unchanged
            // rather than crediting the wallet a second time.
            return $payment;
        }

        $driver = $this->gateways->driver($payment->gateway);
        $result = $driver->verify($payment, $callback);

        if (! $result->successful) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failure_reason' => $result->failureReason,
                'gateway_payload' => $result->raw ?: null,
            ])->save();

            return $payment;
        }

        // Never trust a callback's amount over our own record.
        if ($result->amount !== null && $result->amount !== $payment->amount) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failure_reason' => 'amount_mismatch',
                'gateway_payload' => $result->raw ?: null,
            ])->save();

            throw DomainException::make('payment_amount_mismatch', 422, [
                'expected' => $payment->amount,
                'received' => $result->amount,
            ]);
        }

        return DB::transaction(function () use ($payment, $result): Payment {
            $transaction = $this->wallets->creditTopup(
                wallet: $payment->wallet,
                amount: $payment->amount,
                idempotencyKey: 'topup:'.$payment->uuid,
                subject: $payment,
                metadata: ['gateway' => $payment->gateway, 'reference' => $result->reference],
            );

            $payment->forceFill([
                'status' => PaymentStatus::Succeeded,
                'gateway_reference' => $result->reference,
                'card_mask' => $result->cardMask,
                'gateway_payload' => $result->raw ?: null,
                'wallet_transaction_id' => $transaction->id,
                'paid_at' => now(),
            ])->save();

            return $payment->fresh();
        });
    }

    public function cancel(Payment $payment, string $reason = 'cancelled_by_user'): Payment
    {
        if ($payment->status->isFinal()) {
            return $payment;
        }

        $payment->forceFill([
            'status' => PaymentStatus::Cancelled,
            'failure_reason' => $reason,
        ])->save();

        return $payment;
    }

    /** Expire abandoned payments so they cannot be completed much later. */
    public function expireStale(): int
    {
        return Payment::pending()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'status' => PaymentStatus::Cancelled->value,
                'failure_reason' => 'expired',
            ]);
    }
}
