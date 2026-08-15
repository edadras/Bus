<?php

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Network\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The merchant dossier: the wallet that gets settled, the tills that collect
 * into it, and the people allowed to operate them.
 *
 * Two things must never come back from this endpoint however convenient they
 * would be to display: a till's signing secret, which would let anyone mint a
 * payment QR, and a full IBAN.
 */
class MerchantDossierTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]));

        $this->merchant = $this->createMerchant();
    }

    private function createMerchant(array $overrides = []): Merchant
    {
        $uuid = $this->postJson('/api/v1/admin/merchants', array_merge([
            'name' => 'استخر ساحل',
            'type' => 'swimming_pool',
            'owner_first_name' => 'رضا',
            'owner_last_name' => 'کریمی',
            'owner_mobile' => '09121112233',
        ], $overrides))->assertCreated()->json('data.uuid');

        return Merchant::where('uuid', $uuid)->firstOrFail();
    }

    public function test_the_dossier_carries_the_wallet_tills_and_staff(): void
    {
        $data = $this->getJson("/api/v1/admin/merchants/{$this->merchant->uuid}")
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['wallet']['balance']);
        // Registration issues a default till and makes the owner a manager, so
        // a brand new merchant is already usable rather than half-configured.
        $this->assertCount(1, $data['merchant']['terminals']);
        $this->assertCount(1, $data['staff']);
        $this->assertSame('manager', $data['staff'][0]['role']);
    }

    public function test_a_till_secret_never_leaves_the_server(): void
    {
        $response = $this->getJson("/api/v1/admin/merchants/{$this->merchant->uuid}")->assertOk();

        $response->assertJsonMissingPath('data.merchant.terminals.0.secret');
        // Belt and braces: the secret must not appear anywhere in the payload,
        // whatever key it might have been nested under.
        $this->assertStringNotContainsString(
            $this->merchant->terminals()->firstOrFail()->secret,
            $response->getContent(),
        );
    }

    public function test_the_iban_is_reduced_to_its_last_four_digits(): void
    {
        $this->merchant->forceFill(['iban' => 'IR820540102680020817909002'])->save();

        $bank = $this->getJson("/api/v1/admin/merchants/{$this->merchant->uuid}")
            ->assertOk()
            ->json('data.bank');

        $this->assertTrue($bank['has_iban']);
        $this->assertSame('9002', $bank['iban_last4']);
        $this->assertStringNotContainsString(
            'IR820540102680020817909002',
            $this->getJson("/api/v1/admin/merchants/{$this->merchant->uuid}")->getContent(),
        );
    }

    public function test_a_merchant_with_no_account_on_file_says_so(): void
    {
        $this->getJson("/api/v1/admin/merchants/{$this->merchant->uuid}")
            ->assertOk()
            ->assertJsonPath('data.bank.has_iban', false);
    }

    public function test_adding_a_till_gives_it_its_own_public_id(): void
    {
        $first = $this->merchant->terminals()->firstOrFail();

        $terminal = $this->postJson("/api/v1/admin/merchants/{$this->merchant->uuid}/terminals", [
            'name' => 'گیشه ۲',
            'location_label' => 'ورودی شرقی',
        ])->assertCreated()->json('data');

        // Two tills at one merchant must be distinguishable, or a payment
        // cannot be attributed to the counter that took it.
        $this->assertNotSame($first->public_id, $terminal['public_id']);
        $this->assertSame(2, $this->merchant->terminals()->count());
    }

    public function test_adding_staff_reuses_an_existing_account_rather_than_duplicating_a_person(): void
    {
        $existing = User::factory()->create([
            'city_id' => $this->city->id,
            'mobile' => '989127778899',
        ]);

        $this->postJson("/api/v1/admin/merchants/{$this->merchant->uuid}/staff", [
            'first_name' => 'مینا',
            'last_name' => 'صادقی',
            'mobile' => '09127778899',
            'role' => 'cashier',
        ])->assertCreated()->assertJsonPath('data.user_uuid', $existing->uuid);

        $this->assertSame(1, User::where('mobile', '989127778899')->count());
    }

    public function test_staff_mobiles_are_masked_in_the_dossier(): void
    {
        $this->postJson("/api/v1/admin/merchants/{$this->merchant->uuid}/staff", [
            'first_name' => 'مینا',
            'last_name' => 'صادقی',
            'mobile' => '09127778899',
            'role' => 'cashier',
        ])->assertCreated();

        $content = $this->getJson("/api/v1/admin/merchants/{$this->merchant->uuid}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('989127778899', $content);
    }

    public function test_another_citys_merchant_is_not_reachable(): void
    {
        $other = City::factory()->create(['slug' => 'shiraz']);
        $stranger = Merchant::factory()->create(['city_id' => $other->id]);

        $this->getJson("/api/v1/admin/merchants/{$stranger->uuid}")->assertNotFound();
        $this->postJson("/api/v1/admin/merchants/{$stranger->uuid}/terminals", ['name' => 'x'])->assertNotFound();
    }

    public function test_the_dossier_is_closed_to_a_support_agent(): void
    {
        $this->actingAsAdmin(
            User::factory()->create(['city_id' => $this->city->id]),
            Role::SUPPORT_AGENT,
        );

        $this->getJson("/api/v1/admin/merchants/{$this->merchant->uuid}")->assertForbidden();
    }
}
