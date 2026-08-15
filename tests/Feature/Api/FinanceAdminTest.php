<?php

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The finance screen's endpoints, exercised the way the panel drives them.
 *
 * The rule these tests exist to protect is the one the ledger is built on: a
 * balance is never written directly. Every route here either reads, or posts a
 * balanced pair of entries — reversal writes a mirrored posting rather than
 * editing the original, and an adjustment is a posting like any other.
 */
class FinanceAdminTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private User $admin;

    private User $passenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->admin = User::factory()->create(['city_id' => $this->city->id]);
        $this->passenger = $this->makePassenger($this->city, 500_000);

        $this->actingAsAdmin($this->admin);
    }

    public function test_the_transaction_list_is_filterable_and_paginated(): void
    {
        $response = $this->getJson('/api/v1/admin/finance/transactions?type=topup&per_page=10')
            ->assertOk();

        $this->assertSame(10, $response->json('meta.pagination.per_page'));
        $this->assertNotEmpty($response->json('data'));

        foreach ($response->json('data') as $row) {
            $this->assertSame('topup', $row['type']);
        }
    }

    public function test_a_filter_that_matches_nothing_returns_an_empty_page_not_an_error(): void
    {
        $this->getJson('/api/v1/admin/finance/transactions?status=failed')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_reversing_a_transaction_writes_a_mirror_and_leaves_the_original_alone(): void
    {
        $original = WalletTransaction::forCity($this->city)->firstOrFail();
        $balanceBefore = $this->walletOf($this->passenger)->balance;

        $this->postJson("/api/v1/admin/finance/transactions/{$original->uuid}/reverse", [
            'reason' => 'duplicate top-up from the gateway',
        ])->assertOk()->assertJsonPath('data.original_uuid', $original->uuid);

        // The original row is still there, untouched but marked.
        $this->assertDatabaseHas('wallet_transactions', ['uuid' => $original->uuid]);
        $this->assertSame($original->amount, $original->fresh()->amount);

        $this->assertSame($balanceBefore - $original->amount, $this->walletOf($this->passenger)->balance);
    }

    public function test_a_reversal_without_a_reason_is_refused(): void
    {
        $original = WalletTransaction::forCity($this->city)->firstOrFail();

        // The reason is what a later audit reads; an unexplained reversal is
        // worse than no reversal at all.
        $this->postJson("/api/v1/admin/finance/transactions/{$original->uuid}/reverse", ['reason' => ''])
            ->assertStatus(422);
    }

    public function test_an_adjustment_moves_the_balance_through_the_ledger(): void
    {
        $before = $this->walletOf($this->passenger)->balance;

        $this->postJson('/api/v1/admin/finance/wallets/adjust', [
            'user_uuid' => $this->passenger->uuid,
            'amount' => 120_000,
            'reason' => 'goodwill credit after a failed trip',
        ])->assertOk()->assertJsonPath('data.balance', $before + 120_000);

        // The balance and the postings must still agree: an adjustment that
        // moved the cached figure without a ledger entry would show up here.
        $this->assertTrue(app(LedgerService::class)->verify($this->walletOf($this->passenger))['ok']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.wallet.adjusted']);
    }

    public function test_a_negative_adjustment_debits_the_wallet(): void
    {
        $before = $this->walletOf($this->passenger)->balance;

        $this->postJson('/api/v1/admin/finance/wallets/adjust', [
            'user_uuid' => $this->passenger->uuid,
            'amount' => -50_000,
            'reason' => 'reversing a mistaken manual credit',
        ])->assertOk()->assertJsonPath('data.balance', $before - 50_000);
    }

    public function test_a_zero_adjustment_is_refused(): void
    {
        $this->postJson('/api/v1/admin/finance/wallets/adjust', [
            'user_uuid' => $this->passenger->uuid,
            'amount' => 0,
            'reason' => 'nothing at all',
        ])->assertStatus(422);
    }

    public function test_the_audit_endpoint_reports_a_healthy_wallet(): void
    {
        $this->getJson('/api/v1/admin/finance/wallets/audit?user_uuid='.$this->passenger->uuid)
            ->assertOk()
            ->assertJsonPath('data.integrity.ok', true)
            ->assertJsonPath('data.integrity.drift', 0);
    }

    public function test_user_lookup_finds_a_passenger_by_mobile_in_any_written_form(): void
    {
        $this->passenger->forceFill(['mobile' => '989121234567'])->save();

        foreach (['09121234567', '9121234567', '۰۹۱۲۱۲۳۴۵۶۷'] as $typed) {
            $this->getJson('/api/v1/admin/users/lookup?q='.urlencode($typed))
                ->assertOk()
                ->assertJsonPath('data.0.uuid', $this->passenger->uuid);
        }
    }

    public function test_user_lookup_publishes_the_balance_so_the_operator_need_not_click_twice(): void
    {
        $this->getJson('/api/v1/admin/users/lookup?q='.$this->passenger->uuid)
            ->assertOk()
            ->assertJsonPath('data.0.balance', 500_000);
    }

    public function test_user_lookup_never_reaches_another_city(): void
    {
        $other = City::factory()->create(['slug' => 'shiraz']);
        $stranger = User::factory()->create(['city_id' => $other->id, 'first_name' => 'Mahsa']);

        $this->getJson('/api/v1/admin/users/lookup?q=Mahsa')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNotNull($stranger->fresh());
    }

    public function test_a_mobile_is_masked_unless_the_operator_may_see_identity_data(): void
    {
        $this->passenger->forceFill(['mobile' => '989121234567'])->save();

        // A finance manager settles accounts; they do not need to read phone
        // numbers to do it, so they get enough to recognise the right person.
        $financeUser = User::factory()->create(['city_id' => $this->city->id]);
        $this->actingAsAdmin($financeUser, Role::FINANCE_MANAGER);

        $masked = $this->getJson('/api/v1/admin/users/lookup?q='.$this->passenger->uuid)
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($masked['mobile_is_masked']);
        $this->assertStringContainsString('*', $masked['mobile']);
        $this->assertStringNotContainsString('989121234567', $masked['mobile']);
    }

    public function test_a_two_letter_search_is_refused_rather_than_matching_half_the_city(): void
    {
        $this->getJson('/api/v1/admin/users/lookup?q=ab')->assertStatus(422);
    }

    public function test_the_finance_screen_is_closed_to_a_support_agent(): void
    {
        $agent = User::factory()->create(['city_id' => $this->city->id]);
        $this->actingAsAdmin($agent, Role::SUPPORT_AGENT);

        $this->getJson('/api/v1/admin/finance/transactions')->assertForbidden();
        $this->getJson('/api/v1/admin/users/lookup?q=something')->assertForbidden();
    }
}
