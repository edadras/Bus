<?php

namespace Tests\Feature\Merchant;

use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Enums\MerchantStatus;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Merchant\Models\MerchantTerminal;
use App\Domain\Merchant\Models\MerchantTransaction;
use App\Domain\Merchant\Services\MerchantPaymentService;
use App\Domain\Merchant\Services\MerchantTerminalQrService;
use App\Domain\Merchant\Services\SettlementService;
use App\Domain\Network\Models\City;
use App\Domain\Wallet\Enums\TransactionStatus;
use App\Domain\Wallet\Services\SystemAccountRegistry;
use App\Domain\Wallet\Services\WalletService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\InsufficientFundsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The wallet away from the bus: pools, gyms and shops. The invariant is that
 * the merchant's balance is always exactly what the platform owes them, net of
 * commission, with no later correction step.
 */
class MerchantPaymentTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Merchant $merchant;

    private MerchantTerminal $terminal;

    private MerchantPaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = app(MerchantPaymentService::class);
        $this->city = $this->makeCity();

        $this->merchant = Merchant::factory()->create([
            'city_id' => $this->city->id,
            'commission_bps' => 200, // 2%
        ]);

        $this->terminal = app(MerchantTerminalQrService::class)
            ->issueTerminal($this->merchant, 'صندوق ۱');

        app(WalletService::class)->forMerchant($this->merchant);
    }

    private function token(): string
    {
        return app(QrTokenService::class)->issue($this->terminal->public_id, $this->terminal->secret);
    }

    private function payer(int $balance = 5_000_000): User
    {
        $user = $this->makePassenger($this->city, $balance);
        RateLimiter::clear('merchant-pay:'.$user->id);

        return $user;
    }

    public function test_a_payment_moves_money_and_withholds_commission(): void
    {
        $payer = $this->payer();

        $transaction = $this->payments->charge($payer, $this->token(), 500_000, 'ورودی استخر');

        $this->assertSame(TransactionStatus::Completed, $transaction->status);
        $this->assertSame(500_000, $transaction->amount);
        $this->assertSame(10_000, $transaction->commission_amount);
        $this->assertSame(490_000, $transaction->net_amount);

        $this->assertSame(4_500_000, $this->walletOf($payer)->fresh()->balance);
        $this->assertSame(490_000, app(WalletService::class)->forMerchant($this->merchant)->fresh()->balance);
        $this->assertSame(10_000, app(SystemAccountRegistry::class)->commissionRevenue()->fresh()->balance);
    }

    public function test_the_posting_for_a_merchant_payment_balances(): void
    {
        $transaction = $this->payments->charge($this->payer(), $this->token(), 300_000);

        $this->assertTrue($transaction->walletTransaction->isBalanced());
    }

    public function test_a_terminal_token_cannot_be_replayed(): void
    {
        $token = $this->token();

        $this->payments->charge($this->payer(), $token, 100_000);

        $this->expectException(DomainException::class);
        $this->payments->charge($this->payer(), $token, 100_000);
    }

    public function test_paying_a_suspended_merchant_is_refused(): void
    {
        $this->merchant->forceFill(['status' => MerchantStatus::Suspended])->save();

        $payer = $this->payer();

        try {
            $this->payments->charge($payer, $this->token(), 100_000);
            $this->fail('A suspended merchant must not take payments.');
        } catch (DomainException $e) {
            $this->assertSame('merchant_not_active', $e->errorCode());
        }

        $this->assertSame(5_000_000, $this->walletOf($payer)->fresh()->balance);
    }

    public function test_an_insufficient_balance_leaves_no_record(): void
    {
        $payer = $this->payer(50_000);

        $this->expectException(InsufficientFundsException::class);

        try {
            $this->payments->charge($payer, $this->token(), 500_000);
        } finally {
            $this->assertSame(50_000, $this->walletOf($payer)->fresh()->balance);
            $this->assertSame(0, MerchantTransaction::count());
        }
    }

    public function test_a_charge_above_the_merchant_ceiling_is_refused(): void
    {
        $this->merchant->forceFill(['max_transaction_amount' => 200_000])->save();

        $this->expectException(DomainException::class);
        $this->payments->charge($this->payer(), $this->token(), 500_000);
    }

    public function test_a_refund_returns_the_full_amount_including_commission(): void
    {
        $payer = $this->payer();
        $staff = User::factory()->create();

        $transaction = $this->payments->charge($payer, $this->token(), 500_000);

        $this->payments->refund($transaction, $staff, 'خدمات ارائه نشد');

        $this->assertSame(5_000_000, $this->walletOf($payer)->fresh()->balance);
        $this->assertSame(0, app(WalletService::class)->forMerchant($this->merchant)->fresh()->balance);
        // The platform gives back its commission too; the books stay square.
        $this->assertSame(0, app(SystemAccountRegistry::class)->commissionRevenue()->fresh()->balance);
        $this->assertTrue($transaction->fresh()->isRefunded());
    }

    public function test_a_transaction_cannot_be_refunded_twice(): void
    {
        $transaction = $this->payments->charge($this->payer(), $this->token(), 200_000);
        $staff = User::factory()->create();

        $this->payments->refund($transaction, $staff, 'first');

        $this->expectException(DomainException::class);
        $this->payments->refund($transaction->fresh(), $staff, 'second');
    }

    public function test_a_settled_transaction_cannot_be_refunded(): void
    {
        $transaction = $this->payments->charge($this->payer(), $this->token(), 2_000_000);

        app(SettlementService::class)->draft($this->merchant, now()->subDay(), now());

        try {
            $this->payments->refund($transaction->fresh(), User::factory()->create(), 'too late');
            $this->fail('A settled transaction must not be refundable.');
        } catch (DomainException $e) {
            $this->assertSame('transaction_already_settled', $e->errorCode());
        }
    }

    public function test_a_settlement_claims_its_transactions_exactly_once(): void
    {
        foreach (range(1, 3) as $i) {
            $this->payments->charge($this->payer(), $this->token(), 1_000_000);
        }

        $settlements = app(SettlementService::class);

        $first = $settlements->draft($this->merchant, now()->subDay(), now());

        $this->assertSame(3, $first->transaction_count);
        $this->assertSame(3_000_000, $first->gross_amount);
        $this->assertSame(60_000, $first->commission_amount);
        $this->assertSame(2_940_000, $first->net_amount);

        // A second run in the same window finds nothing left to claim, which is
        // what makes the payout job safe to retry.
        try {
            $settlements->draft($this->merchant, now()->subDay(), now());
            $this->fail('A repeat settlement must not claim the same rows.');
        } catch (DomainException $e) {
            $this->assertSame('nothing_to_settle', $e->errorCode());
        }
    }

    public function test_approving_a_settlement_debits_the_merchant_wallet(): void
    {
        foreach (range(1, 3) as $i) {
            $this->payments->charge($this->payer(), $this->token(), 1_000_000);
        }

        $settlements = app(SettlementService::class);
        $settlement = $settlements->draft($this->merchant, now()->subDay(), now());

        $approved = $settlements->approve($settlement, User::factory()->create());

        $this->assertSame('approved', $approved->status->value);
        $this->assertSame(0, app(WalletService::class)->forMerchant($this->merchant)->fresh()->balance);
        $this->assertSame(
            2_940_000,
            app(SystemAccountRegistry::class)->settlementPayable()->fresh()->balance,
        );
    }

    public function test_rejecting_a_settlement_releases_its_transactions(): void
    {
        $this->payments->charge($this->payer(), $this->token(), 2_000_000);

        $settlements = app(SettlementService::class);
        $settlement = $settlements->draft($this->merchant, now()->subDay(), now());

        $settlements->reject($settlement, User::factory()->create(), 'bank details missing');

        $this->assertSame(
            1,
            MerchantTransaction::settleable()->where('merchant_id', $this->merchant->id)->count(),
            'Released transactions must be settleable again.',
        );
    }
}
