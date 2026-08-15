<?php

namespace Tests\Feature\Api;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuditLogger;
use App\Domain\Network\Models\City;
use App\Domain\Wallet\Models\FareRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit logging runs on every sensitive admin write, so a fault in it takes
 * all of them down at once — which is exactly what happened: the logger
 * mass-assigned `created_at`, a guarded column, and threw wherever
 * `preventSilentlyDiscardingAttributes` is on. That is every environment
 * except production, and nothing covered it because no test had exercised an
 * audited write.
 *
 * These tests exercise the audited paths through the API, so a regression
 * fails the build rather than the first person to click Approve.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->admin = User::factory()->create(['city_id' => $this->city->id]);
        $this->actingAsAdmin($this->admin);
    }

    public function test_the_logger_writes_an_entry_and_stamps_it(): void
    {
        $entry = app(AuditLogger::class)->log('test.action', actor: $this->admin);

        $this->assertNotNull($entry->created_at);
        $this->assertSame($this->admin->id, $entry->user_id);
    }

    public function test_approving_a_driver_is_audited(): void
    {
        $driver = Driver::factory()->pending()->create(['city_id' => $this->city->id]);

        $this->postJson("/api/v1/admin/drivers/{$driver->uuid}/status", ['status' => 'active'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fleet.driver.status_changed',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_regenerating_a_bus_code_is_audited(): void
    {
        $bus = Bus::factory()->inService()->create(['city_id' => $this->city->id]);

        $this->postJson("/api/v1/admin/fleet/buses/{$bus->uuid}/qr/regenerate", [
            'reason' => 'sticker damaged',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'fleet.bus.qr_regenerated']);
    }

    public function test_creating_a_bus_is_audited(): void
    {
        $this->postJson('/api/v1/admin/fleet/buses', [
            'bus_number' => '900',
            'capacity_seated' => 20,
            'capacity_standing' => 20,
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'fleet.bus.created']);
    }

    public function test_changing_a_fare_rule_is_audited(): void
    {
        $rule = FareRule::factory()->create(['city_id' => $this->city->id]);

        $this->patchJson("/api/v1/admin/finance/fare-rules/{$rule->id}", ['base_fare' => 60_000])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.fare_rule.updated']);
    }

    public function test_secrets_never_reach_the_audit_trail(): void
    {
        app(AuditLogger::class)->log('test.action', actor: $this->admin, after: [
            'password' => 'hunter2',
            'national_code' => '0012345678',
            'bus_number' => '102',
        ]);

        $entry = AuditLog::latest('id')->firstOrFail();

        $this->assertSame('[redacted]', $entry->after['password']);
        $this->assertSame('[redacted]', $entry->after['national_code']);
        // Ordinary fields are kept: a redacted-everything log is not an audit.
        $this->assertSame('102', $entry->after['bus_number']);
    }
}
