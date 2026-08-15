<?php

namespace Tests\Feature\Api;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\BusAssignment;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Assigning a bus to a driver is the step that makes the whole system usable:
 * without it `Driver::mayOperate()` refuses, and no shift can start. These
 * tests pin the endpoint the panel drives, and — more importantly — that what
 * the panel *shows* as in force is exactly what the server will *accept*.
 */
class FleetAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Bus $bus;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->bus = Bus::factory()->inService()->create(['city_id' => $this->city->id]);
        $this->driver = Driver::factory()->create(['city_id' => $this->city->id]);

        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]));
    }

    private function assign(array $overrides = []): TestResponse
    {
        return $this->postJson("/api/v1/admin/fleet/buses/{$this->bus->uuid}/assignments", array_merge([
            'driver_uuid' => $this->driver->uuid,
            'starts_on' => today()->toDateString(),
        ], $overrides));
    }

    public function test_assigning_a_driver_lets_them_operate_the_bus(): void
    {
        $this->assertFalse($this->driver->mayOperate($this->bus));

        $this->assign()->assertCreated()->assertJsonPath('data.is_current', true);

        $this->assertTrue($this->driver->fresh()->mayOperate($this->bus));
    }

    public function test_a_driver_is_addressed_by_uuid_and_the_numeric_id_is_never_published(): void
    {
        $response = $this->assign()->assertCreated();

        $this->assertSame($this->driver->uuid, $response->json('data.driver_uuid'));
        $this->assertArrayNotHasKey('driver_id', $response->json('data'));
    }

    public function test_a_future_assignment_is_listed_but_not_in_force(): void
    {
        $this->assign(['starts_on' => today()->addWeek()->toDateString()])->assertCreated();

        $row = $this->getJson("/api/v1/admin/fleet/buses/{$this->bus->uuid}/assignments")
            ->assertOk()
            ->json('data.0');

        // The panel must not tell an operator a driver is ready when the
        // server would refuse the shift.
        $this->assertTrue($row['is_active']);
        $this->assertFalse($row['is_current']);
        $this->assertFalse($this->driver->fresh()->mayOperate($this->bus));
    }

    public function test_an_expired_assignment_is_not_in_force(): void
    {
        BusAssignment::create([
            'bus_id' => $this->bus->id,
            'driver_id' => $this->driver->id,
            'starts_on' => today()->subMonth(),
            'ends_on' => today()->subDay(),
            'is_active' => true,
        ]);

        $row = $this->getJson("/api/v1/admin/fleet/buses/{$this->bus->uuid}/assignments")
            ->assertOk()
            ->json('data.0');

        $this->assertFalse($row['is_current']);
        $this->assertFalse($this->driver->fresh()->mayOperate($this->bus));
    }

    public function test_revoking_an_assignment_stops_the_driver_operating(): void
    {
        $id = $this->assign()->assertCreated()->json('data.id');

        $this->assertTrue($this->driver->fresh()->mayOperate($this->bus));

        $this->deleteJson("/api/v1/admin/fleet/assignments/{$id}")->assertOk();

        $this->assertFalse($this->driver->fresh()->mayOperate($this->bus));
    }

    public function test_a_driver_from_another_city_cannot_be_assigned(): void
    {
        $other = City::factory()->create(['slug' => 'shiraz']);
        $stranger = Driver::factory()->create(['city_id' => $other->id]);

        $this->assign(['driver_uuid' => $stranger->uuid])->assertNotFound();
    }

    public function test_an_end_date_before_the_start_is_refused(): void
    {
        $this->assign([
            'starts_on' => today()->toDateString(),
            'ends_on' => today()->subDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_assignments_require_the_fleet_permission(): void
    {
        $this->actingAsPassenger($this->makePassenger($this->city));

        $this->getJson("/api/v1/admin/fleet/buses/{$this->bus->uuid}/assignments")->assertForbidden();
    }
}
