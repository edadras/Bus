<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role scoping is what makes the admin panel safe to hand to a city operator:
 * a role may be granted globally or for exactly one city.
 */
class PermissionTest extends TestCase
{
    use RefreshDatabase;

    private City $bandarAbbas;

    private City $shiraz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbac();
        $this->bandarAbbas = $this->makeCity();
        $this->shiraz = City::factory()->create(['slug' => 'shiraz']);
    }

    public function test_a_role_grants_its_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::FINANCE_MANAGER, $this->bandarAbbas->id);

        $user = $user->fresh()->load('roles.permissions');

        $this->assertTrue($user->hasPermission('finance.manage'));
        $this->assertFalse($user->hasPermission('fleet.manage'));
    }

    public function test_super_admin_holds_every_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::SUPER_ADMIN);

        $user = $user->fresh()->load('roles.permissions');

        $this->assertTrue($user->hasPermission('finance.manage'));
        $this->assertTrue($user->hasPermission('anything.at.all'));
        $this->assertTrue($user->canAccessCity($this->shiraz));
    }

    public function test_a_city_scoped_role_cannot_reach_another_city(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::TRANSPORT_MANAGER, $this->bandarAbbas->id);

        $user = $user->fresh()->load('roles.permissions');

        $this->assertTrue($user->canAccessCity($this->bandarAbbas));
        $this->assertFalse($user->canAccessCity($this->shiraz));
        $this->assertSame([$this->bandarAbbas->id], $user->scopedCityIds());
    }

    public function test_a_global_role_reaches_every_city(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::ADMIN);

        $user = $user->fresh()->load('roles.permissions');

        $this->assertTrue($user->canAccessCity($this->shiraz));
        $this->assertSame([], $user->scopedCityIds(), 'No scoping means all cities.');
    }

    public function test_removing_a_role_removes_its_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::FINANCE_MANAGER, $this->bandarAbbas->id);
        $this->assertTrue($user->fresh()->load('roles.permissions')->hasPermission('finance.manage'));

        $user->removeRole(Role::FINANCE_MANAGER);

        $this->assertFalse($user->fresh()->load('roles.permissions')->hasPermission('finance.manage'));
    }

    public function test_admin_routes_reject_a_user_without_the_permission(): void
    {
        $agent = User::factory()->create(['city_id' => $this->bandarAbbas->id]);
        $this->actingAsAdmin($agent, Role::SUPPORT_AGENT, $this->bandarAbbas);

        // A support agent may reach the dashboard...
        $this->getJson('/api/v1/admin/dashboard/kpis')->assertOk();

        // ...but must not reach finance.
        $this->getJson('/api/v1/admin/finance/summary')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_a_city_scoped_admin_is_blocked_from_another_city(): void
    {
        $manager = User::factory()->create(['city_id' => $this->bandarAbbas->id]);
        $this->actingAsAdmin($manager, Role::FLEET_MANAGER, $this->bandarAbbas);

        $this->getJson('/api/v1/admin/fleet/buses')->assertOk();

        // The city travels in a header; a scoped role must not follow it.
        $this->withHeader('X-City', 'shiraz')
            ->getJson('/api/v1/admin/fleet/buses')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'city_not_permitted');
    }

    public function test_an_admin_token_is_required_for_admin_routes(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::SUPER_ADMIN);

        // Right role, wrong token ability: the ability gate still refuses.
        $this->actingAsPassenger($user->fresh());

        $this->getJson('/api/v1/admin/dashboard/kpis')->assertStatus(403);
    }

    public function test_an_unknown_city_header_is_rejected(): void
    {
        $this->withHeader('X-City', 'atlantis')
            ->getJson('/api/v1/stops')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'unknown_city');
    }
}
