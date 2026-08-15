<?php

namespace Tests\Feature\Api;

use App\Domain\Fleet\Enums\ShiftStatus;
use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\DriverShift;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\City;
use App\Domain\Operations\Enums\TripStatus;
use App\Domain\Operations\Models\Trip;
use App\Domain\Ridership\Enums\PassengerTripStatus;
use App\Domain\Ridership\Models\PassengerTrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reporting and live-occupancy screens.
 *
 * Two things are being checked: that the aggregates actually count what they
 * claim to, and that the occupancy view stays aggregated — it is the closest
 * thing in the product to a "where is this person" screen, and it must never
 * become one.
 */
class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private BusLine $line;

    private BusRoute $route;

    private Bus $bus;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->line = BusLine::factory()->create(['city_id' => $this->city->id]);
        $this->route = BusRoute::factory()->create(['bus_line_id' => $this->line->id]);
        $this->bus = Bus::factory()->inService()->create([
            'city_id' => $this->city->id,
            'capacity_seated' => 20,
            'capacity_standing' => 20,
        ]);
        $this->driver = Driver::factory()->create(['city_id' => $this->city->id]);
    }

    private function admin(string $role = Role::SUPER_ADMIN): User
    {
        $user = User::factory()->create(['city_id' => $this->city->id]);
        $this->actingAsAdmin($user, $role, $this->city);

        return $user;
    }

    private function makeTrip(array $attributes = []): Trip
    {
        return Trip::factory()->create(array_merge([
            'city_id' => $this->city->id,
            'bus_id' => $this->bus->id,
            'bus_line_id' => $this->line->id,
            'route_id' => $this->route->id,
            'driver_id' => $this->driver->id,
            'status' => TripStatus::Completed,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'passenger_count' => 12,
            'peak_passenger_count' => 14,
            'distance_meters' => 8_400,
        ], $attributes));
    }

    // ── Reports ───────────────────────────────────────────────────────────

    public function test_the_transport_report_counts_completed_trips_in_the_period(): void
    {
        $this->makeTrip();
        $this->makeTrip();

        // Outside the window: must not be counted.
        $this->makeTrip([
            'started_at' => now()->subDays(120),
            'ended_at' => now()->subDays(120)->addHour(),
        ]);

        $this->admin();

        $response = $this->getJson('/api/v1/admin/reports/transport')->assertOk();

        $this->assertSame(2, $response->json('data.trips'));
        $this->assertSame(1, $response->json('data.buses_used'));
        $this->assertSame(16_800, $response->json('data.distance_meters'));
        $this->assertArrayHasKey('punctuality', $response->json('data'));
    }

    public function test_the_driver_report_rolls_up_per_driver_totals(): void
    {
        // Driver figures are rolled up from shifts, which is what a duty
        // roster is actually built from — two trips inside one shift.
        DriverShift::create([
            'driver_id' => $this->driver->id,
            'bus_id' => $this->bus->id,
            'started_at' => now()->subHours(4),
            'ended_at' => now()->subHour(),
            'status' => ShiftStatus::Closed,
            'trip_count' => 2,
            'passenger_count' => 24,
            'revenue_minor' => 1_200_000,
            'distance_meters' => 16_800,
        ]);

        $this->admin();

        $response = $this->getJson('/api/v1/admin/reports/drivers')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['trips']);
        $this->assertSame(24, $rows[0]['passengers']);
        $this->assertNotEmpty($rows[0]['name']);
    }

    public function test_the_passenger_report_aggregates_and_never_lists_individuals(): void
    {
        $trip = $this->makeTrip();

        foreach (range(1, 3) as $index) {
            PassengerTrip::factory()->completed()->create([
                'user_id' => $this->makePassenger($this->city)->id,
                'trip_id' => $trip->id,
                'bus_id' => $this->bus->id,
                'city_id' => $this->city->id,
                'boarded_at' => now()->subHours(2),
                'alighted_at' => now()->subHours(2)->addMinutes(15),
                'fare_amount' => 50_000,
            ]);
        }

        $this->admin();

        $response = $this->getJson('/api/v1/admin/reports/passengers')->assertOk();
        $data = $response->json('data');

        $this->assertSame(3, $data['rides']);
        $this->assertSame(3, $data['active_users']);
        $this->assertSame(150_000, $data['fare_total']);
        $this->assertArrayHasKey('by_hour', $data);
        $this->assertArrayHasKey('frequency', $data);

        // The report is about the population, not about people: no identifier
        // of any single rider may appear in it.
        $body = $response->getContent();
        foreach (User::pluck('mobile') as $mobile) {
            $this->assertStringNotContainsString((string) $mobile, $body);
        }
        $this->assertArrayNotHasKey('users', $data);
    }

    public function test_the_revenue_report_is_gated_behind_the_finance_permission(): void
    {
        // A transport manager runs the network but has no business reading the
        // money side of it.
        $this->admin(Role::TRANSPORT_MANAGER);

        $this->getJson('/api/v1/admin/reports/transport')->assertOk();
        $this->getJson('/api/v1/admin/reports/revenue')->assertForbidden();
    }

    public function test_the_revenue_report_breaks_down_by_line_bus_and_type(): void
    {
        $this->admin();

        $response = $this->getJson('/api/v1/admin/reports/revenue')->assertOk();

        $this->assertArrayHasKey('by_line', $response->json('data'));
        $this->assertArrayHasKey('by_bus', $response->json('data'));
        $this->assertArrayHasKey('by_transaction_type', $response->json('data'));
    }

    public function test_reports_report_the_period_they_cover(): void
    {
        $this->admin();

        $this->getJson('/api/v1/admin/reports/transport?from=2026-01-01&to=2026-01-07')
            ->assertOk()
            ->assertJsonPath('meta.period.from', '2026-01-01')
            ->assertJsonPath('meta.period.to', '2026-01-07')
            ->assertJsonPath('meta.period.days', 7);
    }

    public function test_an_inverted_period_is_rejected(): void
    {
        $this->admin();

        $this->getJson('/api/v1/admin/reports/transport?from=2026-01-07&to=2026-01-01')
            ->assertStatus(422);
    }

    public function test_a_passenger_token_cannot_reach_the_reports(): void
    {
        $this->actingAsPassenger($this->makePassenger($this->city));

        $this->getJson('/api/v1/admin/reports/transport')->assertForbidden();
    }

    // ── Live occupancy ────────────────────────────────────────────────────

    public function test_occupancy_reports_counts_and_crowding_per_vehicle(): void
    {
        $this->makeTrip([
            'status' => TripStatus::Active,
            'ended_at' => null,
            'passenger_count' => 36,
            'current_lat' => 27.1832,
            'current_lng' => 56.2666,
        ]);

        $this->admin();

        $response = $this->getJson('/api/v1/admin/live/occupancy')->assertOk();
        $row = $response->json('data.0');

        $this->assertSame(36, $row['passenger_count']);
        $this->assertSame(40, $row['capacity']);
        $this->assertSame('full', $row['crowding']);
        $this->assertSame(1, $response->json('meta.totals.buses_in_service'));
        $this->assertSame(36, $response->json('meta.totals.passengers_on_board'));
    }

    public function test_occupancy_never_exposes_a_single_passengers_identity_or_position(): void
    {
        $trip = $this->makeTrip([
            'status' => TripStatus::Active,
            'ended_at' => null,
            'passenger_count' => 1,
        ]);

        $rider = $this->makePassenger($this->city);

        PassengerTrip::factory()->create([
            'user_id' => $rider->id,
            'trip_id' => $trip->id,
            'bus_id' => $this->bus->id,
            'city_id' => $this->city->id,
            'status' => PassengerTripStatus::Active,
            'boarded_at' => now()->subMinutes(5),
        ]);

        $this->admin();

        $response = $this->getJson('/api/v1/admin/live/occupancy')->assertOk();
        $body = $response->getContent();

        // With exactly one rider aboard, a leak would be unambiguous.
        $this->assertStringNotContainsString($rider->uuid, $body);
        $this->assertStringNotContainsString((string) $rider->mobile, $body);
        $this->assertSame('aggregated_per_vehicle', $response->json('meta.privacy_note'));
        $this->assertArrayNotHasKey('passengers', $response->json('data.0'));
    }

    public function test_occupancy_requires_the_live_map_permission(): void
    {
        // Support agents handle complaints; watching the fleet is not theirs.
        $this->admin(Role::SUPPORT_AGENT);

        $this->getJson('/api/v1/admin/live/occupancy')->assertForbidden();
    }
}
