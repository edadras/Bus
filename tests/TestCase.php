<?php

namespace Tests;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Services\SystemAccountRegistry;
use App\Domain\Wallet\Services\WalletService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The system counter accounts must exist before any posting; every
        // wallet test depends on them.
        app(SystemAccountRegistry::class)->flush();
    }

    protected function seedRbac(): void
    {
        $this->seed(RbacSeeder::class);
    }

    protected function makeCity(array $attributes = []): City
    {
        return City::factory()->create(array_merge([
            'slug' => 'bandar-abbas',
            'name' => 'بندرعباس',
            'center_lat' => 27.1832,
            'center_lng' => 56.2666,
        ], $attributes));
    }

    /** A passenger with a wallet credited through the real ledger. */
    protected function makePassenger(City $city, int $balance = 0): User
    {
        $user = User::factory()->create(['city_id' => $city->id]);

        $wallet = app(WalletService::class)->forUser($user);

        if ($balance > 0) {
            app(WalletService::class)->creditTopup(
                wallet: $wallet,
                amount: $balance,
                idempotencyKey: 'test-topup:'.$user->uuid,
            );
        }

        return $user->fresh();
    }

    protected function walletOf(User $user): Wallet
    {
        return app(WalletService::class)->forUser($user);
    }

    /** Authenticate as a passenger for API calls, with the right ability. */
    protected function actingAsPassenger(User $user): static
    {
        \Laravel\Sanctum\Sanctum::actingAs($user, ['passenger']);

        return $this;
    }

    protected function actingAsDriver(User $user): static
    {
        \Laravel\Sanctum\Sanctum::actingAs($user, ['driver', 'passenger']);

        return $this;
    }

    protected function actingAsMerchantStaff(User $user): static
    {
        \Laravel\Sanctum\Sanctum::actingAs($user, ['merchant']);

        return $this;
    }

    protected function actingAsAdmin(User $user, string $role = Role::SUPER_ADMIN, ?City $city = null): static
    {
        $this->seedRbac();
        $user->assignRole($role, $city?->id);

        \Laravel\Sanctum\Sanctum::actingAs($user->fresh(), ['admin']);

        return $this;
    }
}
