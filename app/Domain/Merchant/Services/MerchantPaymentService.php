<?php

namespace App\Domain\Merchant\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Merchant\Models\MerchantTerminal;
use App\Domain\Merchant\Models\MerchantTransaction;
use App\Domain\Wallet\Enums\TransactionStatus;
use App\Domain\Wallet\Services\FareEngine;
use App\Domain\Wallet\Services\WalletService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\InsufficientFundsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Payments at pools, gyms, shops — the same wallet, away from the bus network.
 *
 * The passenger scans the till's rotating QR and the platform moves money from
 * their wallet to the merchant's, splitting out commission in the same posting
 * so the merchant's balance is always exactly what is owed to them.
 */
class MerchantPaymentService
{
    public function __construct(
        private readonly MerchantTerminalQrService $terminalQr,
        private readonly WalletService $wallets,
        private readonly FareEngine $fares,
    ) {}

    /**
     * Passenger-initiated: scan the till's code, then confirm the amount the
     * cashier stated. The amount is echoed back for confirmation before the
     * client commits, so it can never be silently changed after the fact.
     */
    public function charge(
        User $payer,
        string $rawToken,
        int $amount,
        ?string $description = null,
        ?string $idempotencyKey = null,
    ): MerchantTransaction {
        $this->assertNotRateLimited($payer);

        $resolved = $this->terminalQr->resolveScan($rawToken);
        $terminal = $resolved['terminal'];
        $token = $resolved['token'];
        $merchant = $terminal->merchant;

        if ($merchant === null || ! $merchant->status->canAcceptPayments()) {
            throw DomainException::make('merchant_not_active', 422);
        }

        $quote = $this->fares->quoteForMerchant($merchant, $amount);

        $payerWallet = $this->wallets->forUser($payer);
        $merchantWallet = $this->wallets->forMerchant($merchant);

        if (! $payerWallet->canSpend($amount)) {
            throw new InsufficientFundsException($amount, $payerWallet->availableBalance());
        }

        $this->terminalQr->consume($token);

        try {
            return DB::transaction(function () use (
                $payer, $merchant, $terminal, $payerWallet, $merchantWallet,
                $amount, $quote, $description, $token, $idempotencyKey
            ): MerchantTransaction {
                $commission = (int) $quote->breakdown['commission'];

                $merchantTransaction = MerchantTransaction::create([
                    'merchant_id' => $merchant->id,
                    'merchant_terminal_id' => $terminal->id,
                    'user_id' => $payer->id,
                    'status' => TransactionStatus::Pending,
                    'amount' => $amount,
                    'commission_amount' => $commission,
                    'net_amount' => $amount - $commission,
                    'currency' => config('wallet.currency'),
                    'reference' => $this->generateReference(),
                    'description' => $description,
                ]);

                $walletTransaction = $this->wallets->chargeMerchant(
                    payer: $payerWallet,
                    merchantWallet: $merchantWallet,
                    amount: $amount,
                    commission: $commission,
                    idempotencyKey: $idempotencyKey ?? 'merchant:'.$token->publicId.':'.$token->nonce.':'.$payer->id,
                    subject: $merchantTransaction,
                    initiatedBy: $payer,
                    metadata: [
                        'merchant' => $merchant->code,
                        'terminal' => $terminal->public_id,
                    ],
                );

                $merchantTransaction->forceFill([
                    'wallet_transaction_id' => $walletTransaction->id,
                    'status' => TransactionStatus::Completed,
                ])->save();

                $terminal->forceFill(['last_used_at' => now()])->save();

                return $merchantTransaction->fresh(['merchant', 'walletTransaction']);
            }, 3);
        } catch (\Throwable $e) {
            $this->terminalQr->release($token);

            throw $e;
        }
    }

    /**
     * Refund a merchant charge by reversing the original posting. The reversal
     * takes the money back out of the merchant's wallet, including their share
     * of the commission, so the books stay consistent.
     */
    public function refund(MerchantTransaction $transaction, User $actor, string $reason): MerchantTransaction
    {
        if ($transaction->status !== TransactionStatus::Completed) {
            throw DomainException::make('transaction_not_refundable', 422);
        }

        if ($transaction->isRefunded()) {
            throw DomainException::make('transaction_already_refunded', 422);
        }

        if (! $transaction->merchant->allows_refund) {
            throw DomainException::make('merchant_refunds_disabled', 403);
        }

        if ($transaction->settlement_id !== null) {
            throw DomainException::make('transaction_already_settled', 422, [
                'settlement' => $transaction->settlement?->reference,
            ]);
        }

        return DB::transaction(function () use ($transaction, $actor, $reason): MerchantTransaction {
            $reversal = $this->wallets->refund($transaction->walletTransaction, $reason, $actor);

            $transaction->forceFill([
                'status' => TransactionStatus::Reversed,
                'refunded_by' => $actor->id,
                'refunded_at' => now(),
                'refund_transaction_id' => $reversal->id,
            ])->save();

            return $transaction->fresh();
        });
    }

    private function assertNotRateLimited(User $user): void
    {
        $key = 'merchant-pay:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('wallet.fraud.max_payments_per_minute'))) {
            throw DomainException::make('too_many_attempts', 429, [
                'retry_after' => RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    private function generateReference(): string
    {
        do {
            $reference = 'MT'.now()->format('ymd').Str::upper(Str::random(8));
        } while (MerchantTransaction::where('reference', $reference)->exists());

        return $reference;
    }
}
