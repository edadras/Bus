<?php

namespace Tests\Feature\Operations;

use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use App\Domain\Network\Models\City;
use App\Domain\Operations\DTO\LocationPing;
use App\Domain\Operations\Enums\TripEventType;
use App\Domain\Operations\Enums\TripStatus;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripEvent;
use App\Domain\Operations\Models\TripLocation;
use App\Domain\Operations\Services\LocationIngestService;
use App\Domain\Operations\Services\RouteMatcher;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The GPS hot path. A faulty or spoofed device must not be able to corrupt
 * distance totals, historical statistics or the live map.
 */
class LocationIngestTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private BusRoute $route;

    private Trip $trip;

    /** @var array<int, BusStop> */
    private array $stops = [];

    private LocationIngestService $ingest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingest = app(LocationIngestService::class);
        $this->city = $this->makeCity();

        $line = BusLine::factory()->create(['city_id' => $this->city->id]);

        $this->route = BusRoute::factory()->create([
            'bus_line_id' => $line->id,
            'geometry' => [
                ['lat' => 27.150, 'lng' => 56.250],
                ['lat' => 27.186, 'lng' => 56.250],
            ],
        ]);

        foreach (range(0, 3) as $index) {
            $stop = BusStop::factory()->at(27.150 + $index * 0.012, 56.250)->create([
                'city_id' => $this->city->id,
            ]);

            $this->stops[$index] = $stop;
            $this->route->routeStops()->create(['bus_stop_id' => $stop->id, 'sequence' => $index + 1]);
        }

        app(RouteMatcher::class)->recalculateStopOffsets($this->route);

        $this->trip = Trip::factory()->create([
            'city_id' => $this->city->id,
            'bus_line_id' => $line->id,
            'route_id' => $this->route->id,
            'status' => TripStatus::Active,
            'last_ping_at' => null,
            'current_lat' => null,
            'current_lng' => null,
        ]);
    }

    private function ping(float $lat, float $lng, float $speed = 30.0, array $overrides = []): array
    {
        return $this->ingest->ingest($this->trip->fresh(['route', 'bus', 'line', 'nextStop']), LocationPing::fromArray(array_merge([
            'lat' => $lat,
            'lng' => $lng,
            'speed' => $speed,
            'accuracy' => 8,
        ], $overrides)));
    }

    public function test_a_ping_is_stored_and_matched_to_the_route(): void
    {
        // Roughly a third of the way up the corridor.
        $result = $this->ping(27.162, 56.2501);

        $this->assertTrue($result['accepted']);
        $this->assertSame(1, TripLocation::count());

        $trip = $this->trip->fresh();

        $this->assertEqualsWithDelta(1_330, $trip->route_offset_meters, 200);
        $this->assertNotNull($trip->next_stop_id);
        $this->assertFalse($trip->is_off_route);
    }

    public function test_the_next_stop_and_distance_advance_as_the_bus_moves(): void
    {
        $this->ping(27.151, 56.2501);
        $first = $this->trip->fresh();

        // Move well past the second stop.
        $this->travel(60)->seconds();
        $this->ping(27.176, 56.2501);
        $second = $this->trip->fresh();

        $this->assertGreaterThan($first->route_offset_meters, $second->route_offset_meters);
        $this->assertNotSame($first->next_stop_id, $second->next_stop_id);
    }

    public function test_arriving_at_a_stop_raises_exactly_one_event(): void
    {
        // Sit inside the geofence of stop 1 across three consecutive pings.
        foreach (range(1, 3) as $i) {
            $this->travel(120)->seconds();
            $this->ping($this->stops[1]->lat, $this->stops[1]->lng, 2.0);
        }

        $arrivals = TripEvent::where('trip_id', $this->trip->id)
            ->where('type', TripEventType::StopArrived->value)
            ->count();

        $this->assertSame(1, $arrivals, 'A bus idling at a stop must not spam arrivals.');
    }

    public function test_a_ping_with_poor_accuracy_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        try {
            $this->ping(27.162, 56.250, 30.0, ['accuracy' => 500]);
        } finally {
            $this->assertSame(0, TripLocation::count());
        }
    }

    public function test_an_impossible_speed_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->ping(27.162, 56.250, 400.0);
    }

    public function test_a_teleport_between_consecutive_pings_is_rejected(): void
    {
        $this->ping(27.151, 56.2501);

        $this->travel(5)->seconds();

        try {
            // 50 km away, five seconds later.
            $this->ping(27.600, 56.700);
            $this->fail('An impossible jump must be rejected.');
        } catch (DomainException $e) {
            $this->assertSame('gps_jump_detected', $e->errorCode());
        }

        $this->assertSame(1, TripLocation::count());
    }

    public function test_a_future_timestamp_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        $this->ping(27.162, 56.250, 30.0, [
            'recorded_at' => CarbonImmutable::now()->addHour()->toIso8601String(),
        ]);
    }

    public function test_pings_arriving_too_quickly_are_throttled_rather_than_stored(): void
    {
        $this->ping(27.151, 56.2501);

        // The app retried immediately; the server acknowledges but does not
        // write a second row.
        $result = $this->ping(27.1511, 56.2501);

        $this->assertFalse($result['accepted']);
        $this->assertSame(1, TripLocation::count());
    }

    public function test_a_single_deviating_ping_does_not_raise_off_route(): void
    {
        // ~500 m to the east of the corridor: well outside the threshold.
        $this->ping(27.162, 56.2555);

        $this->assertFalse($this->trip->fresh()->is_off_route, 'One bad fix must not page the control room.');
    }

    public function test_repeated_deviation_raises_off_route_and_recovery_clears_it(): void
    {
        $threshold = (int) config('transit.gps.off_route_ping_threshold');

        for ($i = 0; $i < $threshold; $i++) {
            $this->travel(60)->seconds();
            $this->ping(27.162 + $i * 0.0001, 56.2555);
        }

        $this->assertTrue($this->trip->fresh()->is_off_route);
        $this->assertSame(1, TripEvent::where('type', TripEventType::OffRoute->value)->count());

        $this->travel(60)->seconds();
        $this->ping(27.163, 56.2501);

        $this->assertFalse($this->trip->fresh()->is_off_route);
        $this->assertSame(1, TripEvent::where('type', TripEventType::BackOnRoute->value)->count());
    }

    public function test_the_reporting_cadence_adapts_to_what_the_bus_is_doing(): void
    {
        $moving = $this->ping(27.155, 56.2501, 40.0);

        $this->assertSame(config('transit.gps.cadence.moving'), $moving['next_ping_in']);

        // Close to a stop, the app should report more often.
        $this->travel(60)->seconds();
        $approaching = $this->ping($this->stops[1]->lat - 0.0015, 56.2501, 20.0);

        $this->assertSame(config('transit.gps.cadence.approaching_stop'), $approaching['next_ping_in']);
    }

    public function test_a_completed_trip_rejects_telemetry(): void
    {
        $this->trip->forceFill(['status' => TripStatus::Completed])->save();

        $this->expectException(DomainException::class);
        $this->ping(27.162, 56.2501);
    }

    public function test_travelled_distance_accumulates_across_pings(): void
    {
        $this->ping(27.151, 56.2501);
        $this->travel(60)->seconds();
        $this->ping(27.170, 56.2501);

        // Roughly 2.1 km between the two positions.
        $this->assertEqualsWithDelta(2_100, $this->trip->fresh()->distance_meters, 300);
    }
}
