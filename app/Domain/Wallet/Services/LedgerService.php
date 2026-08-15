<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\DTO\PostingLine;
use App\Domain\Wallet\DTO\PostingRequest;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\TransactionStatus;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletLedgerEntry;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\InsufficientFundsException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only code in the platform permitted to move money.
 *
 * Guarantees, in order of how they are enforced:
 *
 *  1. Balanced   - every posting's legs sum to zero, checked before any write.
 *  2. Atomic     - the header, all legs and every cached balance land in one
 *                  database transaction, or none of them do.
 *  3. Serialised - wallets are locked with SELECT ... FOR UPDATE in a stable
 *                  order (by id) so two concurrent postings touching the same
 *                  pair of wallets can never deadlock or interleave.
 *  4. Idempotent - a unique index on idempotency_key means a retried request
 *                  returns the original transaction instead of double posting,
 *                  even when the retry races the original.
 *  5. Immutable  - ledger rows reject updates and deletes at the model layer;
 *                  a correction is a new, reversing transaction.
 */
class LedgerService
{
    public function __construct(private readonly SystemAccountRegistry $systemAccounts) {}

    public function post(PostingRequest $request): WalletTransaction
    {
        $this->assertWellFormed($request);

        if ($request->idempotencyKey !== null) {
            $existing = WalletTransaction::where('idempotency_key', $request->idempotencyKey)->first();

            if ($existing !== null) {
                return $existing->load('entries');
            }
        }

        try {
            return DB::transaction(function () use ($request): WalletTransaction {
                return $this->write($request);
            }, 3);
        } catch (QueryException $e) {
            // The unique index is the real arbiter: if a concurrent request won
            // the race, return its transaction rather than surfacing a 500.
            if ($request->idempotencyKey !== null && $this->isUniqueViolation($e)) {
                $existing = WalletTransaction::where('idempotency_key', $request->idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing->load('entries');
                }
            }

            throw $e;
        }
    }

    private function write(PostingRequest $request): WalletTransaction
    {
        // Deterministic lock order across all participating wallets prevents
        // the classic A-then-B / B-then-A deadlock between two transfers.
        $walletIds = collect($request->lines)->pluck('wallet.id')->unique()->sort()->values()->all();

        $locked = Wallet::whereIn('id', $walletIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($locked->count() !== count($walletIds)) {
            throw DomainException::make('wallet_not_found', 404);
        }

        foreach ($request->lines as $line) {
            $wallet = $locked[$line->wallet->id];

            $this->assertCurrencyMatches($wallet, $line);

            if ($line->direction === LedgerDirection::Debit) {
                $this->assertCanDebit($wallet, $line->amount, $request);
            } elseif (! $wallet->status->canCredit()) {
                throw DomainException::make('wallet_closed', 422, ['wallet' => $wallet->uuid]);
            }
        }

        $transaction = WalletTransaction::create([
            'type' => $request->type,
            'status' => TransactionStatus::Completed,
            'currency' => config('wallet.currency'),
            'amount' => $request->amount,
            'idempotency_key' => $request->idempotencyKey,
            'initiated_by' => $request->initiatedBy?->id,
            'city_id' => $request->cityId,
            'subject_type' => $request->subject !== null ? $request->subject::class : null,
            'subject_id' => $request->subject?->getKey(),
            'description' => $request->description,
            'metadata' => $request->metadata ?: null,
            'posted_at' => now(),
        ]);

        foreach ($request->lines as $line) {
            $wallet = $locked[$line->wallet->id];

            $balanceAfter = $wallet->balance + $line->signedAmount();
            $sequence = $wallet->version + 1;

            WalletLedgerEntry::create([
                'wallet_transaction_id' => $transaction->id,
                'wallet_id' => $wallet->id,
                'direction' => $line->direction,
                'amount' => $line->amount,
                'currency' => $wallet->currency,
                'balance_after' => $balanceAfter,
                'sequence' => $sequence,
                'description' => $line->description ?? $request->description,
                'metadata' => $line->metadata ?: null,
            ]);

            $wallet->forceFill([
                'balance' => $balanceAfter,
                'version' => $sequence,
            ])->save();

            // Keep the caller's in-memory instance truthful too; services
            // frequently read the balance straight after posting.
            $line->wallet->setAttribute('balance', $balanceAfter);
            $line->wallet->setAttribute('version', $sequence);
        }

        return $transaction->load('entries');
    }

    /**
     * Write the exact mirror image of a completed transaction. The original is
     * left untouched — reversal is an addition to history, not an edit of it.
     */
    public function reverse(
        WalletTransaction $original,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): WalletTransaction {
        if ($original->status !== TransactionStatus::Completed) {
            throw DomainException::make('transaction_not_reversible', 422, [
                'status' => $original->status->value,
            ]);
        }

        if ($original->reversal()->exists()) {
            throw DomainException::make('transaction_already_reversed', 422);
        }

        $original->loadMissing('entries.wallet');

        $lines = $original->entries->map(fn (WalletLedgerEntry $entry) => new PostingLine(
            wallet: $entry->wallet,
            direction: $entry->direction->opposite(),
            amount: $entry->amount,
            description: $reason,
        ))->all();

        $reversal = $this->post(new PostingRequest(
            type: TransactionType::Reversal,
            lines: $lines,
            amount: $original->amount,
            idempotencyKey: $idempotencyKey ?? 'reversal:'.$original->uuid,
            subject: $original->subject,
            cityId: $original->city_id,
            description: $reason ?? __('wallet.reversal_of', ['reference' => $original->uuid]),
            metadata: ['reverses' => $original->uuid, 'reason' => $reason],
            bypassSpendLimit: true,
        ));

        $reversal->forceFill(['reverses_transaction_id' => $original->id])->save();
        $original->forceFill(['status' => TransactionStatus::Reversed])->save();

        return $reversal;
    }

    /** Recompute a wallet's balance from its ledger; used by the audit command. */
    public function recomputeBalance(Wallet $wallet): int
    {
        return (int) $wallet->ledgerEntries()
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS total")
            ->value('total');
    }

    /** @return array{ok: bool, cached: int, computed: int, drift: int} */
    public function verify(Wallet $wallet): array
    {
        $computed = $this->recomputeBalance($wallet);

        return [
            'ok' => $computed === $wallet->balance,
            'cached' => $wallet->balance,
            'computed' => $computed,
            'drift' => $wallet->balance - $computed,
        ];
    }

    private function assertWellFormed(PostingRequest $request): void
    {
        if ($request->lines === []) {
            throw DomainException::make('posting_has_no_lines', 500);
        }

        if ($request->amount <= 0) {
            throw DomainException::make('amount_must_be_positive', 422, ['amount' => $request->amount]);
        }

        foreach ($request->lines as $line) {
            if ($line->amount <= 0) {
                throw DomainException::make('amount_must_be_positive', 422);
            }
        }

        if (! $request->isBalanced()) {
            // Reaching here means a caller built an unbalanced posting; that is
            // a programming error, never something a client can trigger.
            throw DomainException::make('posting_not_balanced', 500, [
                'imbalance' => array_sum(array_map(fn (PostingLine $l) => $l->signedAmount(), $request->lines)),
            ]);
        }
    }

    private function assertCurrencyMatches(Wallet $wallet, PostingLine $line): void
    {
        if ($wallet->currency !== config('wallet.currency')) {
            throw DomainException::make('currency_mismatch', 422, [
                'wallet' => $wallet->currency,
                'posting' => config('wallet.currency'),
            ]);
        }
    }

    private function assertCanDebit(Wallet $wallet, int $amount, PostingRequest $request): void
    {
        if (! $wallet->status->canDebit()) {
            throw DomainException::make('wallet_frozen', 422, ['wallet' => $wallet->uuid]);
        }

        if (! $wallet->canSpend($amount)) {
            throw new InsufficientFundsException($amount, $wallet->availableBalance());
        }

        if ($request->bypassSpendLimit || $wallet->isSystem()) {
            return;
        }

        $limit = $wallet->daily_spend_limit ?? (int) config('wallet.limits.max_daily_spend');

        if ($limit > 0 && $wallet->spentToday() + $amount > $limit) {
            throw DomainException::make('daily_spend_limit_exceeded', 422, [
                'limit' => $limit,
                'spent_today' => $wallet->spentToday(),
            ]);
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000/23505 cover MySQL and PostgreSQL; SQLite reports 23000 too.
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
