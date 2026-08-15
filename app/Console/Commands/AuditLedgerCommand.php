<?php

namespace App\Console\Commands;

use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletLedgerEntry;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\LedgerService;
use Illuminate\Console\Command;

/**
 * Independent verification that the books are intact. Runs three checks that
 * should each be trivially true, and are the first things to fail if a bug
 * ever slips into the ledger:
 *
 *   1. every wallet's cached balance equals the sum of its postings
 *   2. every transaction's own postings sum to zero
 *   3. all postings across the whole system sum to zero
 */
class AuditLedgerCommand extends Command
{
    protected $signature = 'transit:ledger:audit {--fix : Repair drifted cached balances from the ledger}';

    protected $description = 'Verify wallet balances and double-entry integrity';

    public function handle(LedgerService $ledger): int
    {
        $failures = 0;

        // 1. Cached balances versus their postings.
        $drifted = [];

        Wallet::query()->chunkById(200, function ($wallets) use ($ledger, &$drifted): void {
            foreach ($wallets as $wallet) {
                $result = $ledger->verify($wallet);

                if (! $result['ok']) {
                    $drifted[] = [$wallet->uuid, $wallet->account_ref ?? $wallet->owner_type->value, $result['cached'], $result['computed'], $result['drift']];
                }
            }
        });

        if ($drifted !== []) {
            $failures++;
            $this->error('Wallets whose cached balance disagrees with their ledger:');
            $this->table(['Wallet', 'Owner', 'Cached', 'Computed', 'Drift'], $drifted);

            if ($this->option('fix')) {
                foreach ($drifted as [$uuid]) {
                    $wallet = Wallet::where('uuid', $uuid)->first();
                    $wallet?->forceFill(['balance' => $ledger->recomputeBalance($wallet)])->save();
                }

                $this->warn('Cached balances repaired from the ledger. The ledger itself was not modified.');
            }
        } else {
            $this->info('✓ All wallet balances match their ledger.');
        }

        // 2. Per-transaction balance.
        $unbalanced = WalletTransaction::query()
            ->select('wallet_transactions.id', 'wallet_transactions.uuid')
            ->join('wallet_ledger_entries as e', 'e.wallet_transaction_id', '=', 'wallet_transactions.id')
            ->groupBy('wallet_transactions.id', 'wallet_transactions.uuid')
            ->havingRaw("SUM(CASE WHEN e.direction = 'credit' THEN e.amount ELSE -e.amount END) <> 0")
            ->limit(50)
            ->get();

        if ($unbalanced->isNotEmpty()) {
            $failures++;
            $this->error("Unbalanced transactions: {$unbalanced->count()}");
            $this->table(['ID', 'UUID'], $unbalanced->map(fn ($t) => [$t->id, $t->uuid])->all());
        } else {
            $this->info('✓ Every transaction balances to zero.');
        }

        // 3. System-wide sum.
        $globalSum = (int) WalletLedgerEntry::selectRaw(
            "COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) as total"
        )->value('total');

        if ($globalSum !== 0) {
            $failures++;
            $this->error("System-wide ledger sum is $globalSum, expected 0.");
        } else {
            $this->info('✓ System-wide ledger sums to zero.');
        }

        if ($failures > 0) {
            $this->newLine();
            $this->error("Ledger audit failed with $failures issue group(s).");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Ledger audit passed.');

        return self::SUCCESS;
    }
}
