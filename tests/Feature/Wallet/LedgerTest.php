<?php

namespace Tests\Feature\Wallet;

use App\Domain\Merchant\Models\Merchant;
use App\Domain\Operations\Models\Trip;
use App\Domain\Wallet\DTO\PostingLine;
use App\Domain\Wallet\DTO\PostingRequest;
use App\Domain\Wallet\Enums\TransactionStatus;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Models\WalletLedgerEntry;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\LedgerService;
use App\Domain\Wallet\Services\SystemAccountRegistry;
use App\Domain\Wallet\Services\WalletService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\InsufficientFundsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ledger is the part of this system that must never be wrong. These tests
 * pin down each guarantee it claims.
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private WalletService $wallets;

    private SystemAccountRegistry $system;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(LedgerService::class);
        $this->wallets = app(WalletService::class);
        $this->system = app(SystemAccountRegistry::class);
    }

    public function test_a_posting_moves_money_and_balances_to_zero(): void
    {
        $city = $this->makeCity();
        $user = $this->makePassenger($city);
        $wallet = $this->walletOf($user);

        $transaction = $this->wallets->creditTopup($wallet, 1_000_000, 'topup-1');

        $this->assertSame(1_000_000, $wallet->fresh()->balance);
        $this->assertTrue($transaction->isBalanced());
        $this->assertSame(TransactionStatus::Completed, $transaction->status);

        // The counterparty is debited by exactly the same amount.
        $this->assertSame(-1_000_000, $this->system->gatewayClearing()->fresh()->balance);
    }

    public function test_every_posting_records_a_running_balance_and_sequence(): void
    {
        $city = $this->makeCity();
        $user = $this->makePassenger($city, 1_000_000);
        $wallet = $this->walletOf($user);

        $this->wallets->creditTopup($wallet, 500_000, 'topup-2');

        $entries = $wallet->ledgerEntries()->orderBy('sequence')->get();

        $this->assertSame([1, 2], $entries->pluck('sequence')->all());
        $this->assertSame([1_000_000, 1_500_000], $entries->pluck('balance_after')->all());
    }

    public function test_an_unbalanced_posting_is_rejected_before_anything_is_written(): void
    {
        $city = $this->makeCity();
        $wallet = $this->walletOf($this->makePassenger($city));

        $this->expectException(DomainException::class);

        try {
            $this->ledger->post(new PostingRequest(
                type: TransactionType::Adjustment,
                // Deliberately lopsided: 100 in, 50 out.
                lines: [
                    PostingLine::credit($wallet, 100),
                    PostingLine::debit($this->system->adjustments(), 50),
                ],
                amount: 100,
            ));
        } finally {
            $this->assertSame(0, WalletTransaction::count(), 'No transaction may be written.');
            $this->assertSame(0, WalletLedgerEntry::count(), 'No ledger entry may be written.');
        }
    }

    public function test_idempotency_key_prevents_a_retry_from_posting_twice(): void
    {
        $city = $this->makeCity();
        $wallet = $this->walletOf($this->makePassenger($city));

        $first = $this->wallets->creditTopup($wallet, 250_000, 'same-key');
        $second = $this->wallets->creditTopup($wallet, 250_000, 'same-key');

        $this->assertSame($first->id, $second->id, 'The retry must return the original transaction.');
        $this->assertSame(250_000, $wallet->fresh()->balance, 'The balance must be credited once.');
        $this->assertSame(1, WalletTransaction::where('type', TransactionType::Topup)->count());
    }

    public function test_a_wallet_cannot_be_debited_below_zero(): void
    {
        $city = $this->makeCity();
        $user = $this->makePassenger($city, 30_000);
        $wallet = $this->walletOf($user);
        $trip = Trip::factory()->create(['city_id' => $city->id]);

        $this->expectException(InsufficientFundsException::class);

        try {
            $this->wallets->chargeFare($wallet, 50_000, 'overspend', $trip);
        } finally {
            $this->assertSame(30_000, $wallet->fresh()->balance, 'The balance must be untouched.');
        }
    }

    public function test_ledger_entries_cannot_be_modified_or_deleted(): void
    {
        $city = $this->makeCity();
        $wallet = $this->walletOf($this->makePassenger($city, 100_000));

        $entry = $wallet->ledgerEntries()->first();

        $this->expectException(DomainException::class);
        $entry->update(['amount' => 1]);
    }

    public function test_ledger_entries_cannot_be_deleted(): void
    {
        $city = $this->makeCity();
        $wallet = $this->walletOf($this->makePassenger($city, 100_000));

        $this->expectException(DomainException::class);
        $wallet->ledgerEntries()->first()->delete();
    }

    public function test_reversal_writes_a_mirror_posting_and_leaves_history_intact(): void
    {
        $city = $this->makeCity();
        $user = $this->makePassenger($city, 200_000);
        $wallet = $this->walletOf($user);
        $trip = Trip::factory()->create(['city_id' => $city->id]);

        $fare = $this->wallets->chargeFare($wallet, 50_000, 'fare-1', $trip);
        $this->assertSame(150_000, $wallet->fresh()->balance);

        $reversal = $this->ledger->reverse($fare, 'incorrect charge');

        $this->assertSame(200_000, $wallet->fresh()->balance, 'The money must come back.');
        $this->assertSame(TransactionType::Reversal, $reversal->type);
        $this->assertSame($fare->id, $reversal->reverses_transaction_id);
        $this->assertSame(TransactionStatus::Reversed, $fare->fresh()->status);

        // The original postings still exist — reversal adds, never edits.
        $this->assertSame(2, $fare->entries()->count());
        $this->assertTrue($reversal->isBalanced());
    }

    public function test_a_transaction_cannot_be_reversed_twice(): void
    {
        $city = $this->makeCity();
        $wallet = $this->walletOf($this->makePassenger($city, 200_000));
        $trip = Trip::factory()->create(['city_id' => $city->id]);

        $fare = $this->wallets->chargeFare($wallet, 50_000, 'fare-2', $trip);
        $this->ledger->reverse($fare, 'first');

        $this->expectException(DomainException::class);
        $this->ledger->reverse($fare->fresh(), 'second');
    }

    public function test_a_frozen_wallet_cannot_be_debited(): void
    {
        $city = $this->makeCity();
        $wallet = $this->walletOf($this->makePassenger($city, 500_000));
        $trip = Trip::factory()->create(['city_id' => $city->id]);

        $this->wallets->freeze($wallet, 'suspected fraud');

        $this->expectException(DomainException::class);

        try {
            $this->wallets->chargeFare($wallet->fresh(), 50_000, 'frozen-fare', $trip);
        } finally {
            $this->assertSame(500_000, $wallet->fresh()->balance);
        }
    }

    public function test_verify_detects_a_tampered_cached_balance(): void
    {
        $city = $this->makeCity();
        $wallet = $this->walletOf($this->makePassenger($city, 100_000));

        // Simulate corruption of the cached column without touching the ledger.
        $wallet->forceFill(['balance' => 999_999])->save();

        $result = $this->ledger->verify($wallet->fresh());

        $this->assertFalse($result['ok']);
        $this->assertSame(100_000, $result['computed']);
        $this->assertSame(899_999, $result['drift']);
    }

    public function test_merchant_payment_splits_commission_within_one_balanced_posting(): void
    {
        $city = $this->makeCity();
        $payer = $this->makePassenger($city, 1_000_000);
        $merchant = Merchant::factory()->create([
            'city_id' => $city->id,
            'commission_bps' => 200, // 2%
        ]);

        $merchantWallet = $this->wallets->forMerchant($merchant);

        $transaction = $this->wallets->chargeMerchant(
            payer: $this->walletOf($payer),
            merchantWallet: $merchantWallet,
            amount: 500_000,
            commission: 10_000,
            idempotencyKey: 'mrc-1',
            subject: $merchant,
        );

        $this->assertTrue($transaction->isBalanced());
        $this->assertSame(500_000, $this->walletOf($payer)->fresh()->balance);
        $this->assertSame(490_000, $merchantWallet->fresh()->balance);
        $this->assertSame(10_000, $this->system->commissionRevenue()->fresh()->balance);
    }
}
