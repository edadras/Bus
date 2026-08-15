<?php

namespace Tests\Feature\Operations;

use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use App\Domain\Network\Models\City;
use App\Domain\Operations\Models\SegmentTravelStat;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Services\EtaEngine;
use App\Domain\Operations\Services\RouteMatcher;
use App\Support\Time\TimeBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ETA engine has to stay sane in the cases that break naive
 * distance-over-speed maths: a stopped bus, a bus with no history, and a stop
 * the bus has already gone past.
 */
class EtaEngineTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private BusRoute $route;

    /** @var array<int, BusStop> */
    private array $stops = [];

    private EtaEngine $eta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eta = app(EtaEngine::class);
        $this->city = $this->makeCity();

        $line = BusLine::factory()->create(['city_id' => $this->city->id]);

        // A straight 4 km north-south corridor with five evenly spaced stops,
        // so expected distances are easy to reason about.
        $this->route = BusRoute::factory()->create([
            'bus_line_id' => $line->id,
            'geometry' => [
                ['lat' => 27.150, 'lng' => 56.250],
                ['lat' => 27.186, 'lng' => 56.250],
            ],
        ]);

        foreach (range(0, 4) as $index) {
            $stop = BusStop::factory()->at(27.150 + $index * 0.009, 56.250)->create([
                'city_id' => $this->city->id,
            ]);

            $this->stops[$index] = $stop;

            $this->route->routeStops()->create([
                'bus_stop_id' => $stop->id,
                'sequence' => $index + 1,
                'dwell_seconds' => 20,
            ]);
        }

        app(RouteMatcher::class)->recalculateStopOffsets($this->route);
        $this->route->refresh();
    }

    private function tripAt(int $offsetMeters, float $speedKmh = 30.0): Trip
    {
        return Trip::factory()->create([
            'city_id' => $this->city->id,
            'bus_line_id' => $this->route->bus_line_id,
            'route_id' => $this->route->id,
            'route_offset_meters' => $offsetMeters,
            'current_speed_kmh' => $speedKmh,
            'last_ping_at' => now(),
        ]);
    }

    public function test_it_estimates_arrival_at_a_stop_ahead(): void
    {
        $trip = $this->tripAt(0, 30.0);

        $estimate = $this->eta->estimate($trip, $this->stops[2]);

        $this->assertNotNull($estimate);
        $this->assertSame(2, $estimate->stopsAway);
        $this->assertGreaterThan(0, $estimate->seconds);
        // ~2 km at a blended speed around 22-30 km/h, plus one dwell.
        $this->assertEqualsWithDelta(280, $estimate->seconds, 150);
    }

    public function test_a_stop_already_passed_returns_no_estimate(): void
    {
        // The bus is beyond stop 1; it will not arrive there on this trip.
        $trip = $this->tripAt(2_500);

        $this->assertNull($this->eta->estimate($trip, $this->stops[0]));
        $this->assertNull($this->eta->estimate($trip, $this->stops[1]));
    }

    public function test_a_stop_not_on_the_route_returns_no_estimate(): void
    {
        $trip = $this->tripAt(0);
        $elsewhere = BusStop::factory()->create(['city_id' => $this->city->id]);

        $this->assertNull($this->eta->estimate($trip, $elsewhere));
    }

    public function test_a_stopped_bus_still_produces_a_finite_estimate(): void
    {
        // Speed 0 would make distance/speed infinite; the baseline must carry it.
        $trip = $this->tripAt(0, 0.0);

        $estimate = $this->eta->estimate($trip, $this->stops[1]);

        $this->assertNotNull($estimate);
        $this->assertGreaterThan(0, $estimate->seconds);
        $this->assertLessThan(3600, $estimate->seconds);
    }

    public function test_an_idle_bus_is_penalised_relative_to_a_moving_one(): void
    {
        $moving = $this->tripAt(0, 25.0);
        $idle = $this->tripAt(0, 0.0);
        $idle->forceFill(['is_idle' => true])->save();

        $movingEta = $this->eta->estimate($moving, $this->stops[2]);
        $idleEta = $this->eta->estimate($idle->fresh(), $this->stops[2]);

        $this->assertGreaterThan($movingEta->seconds, $idleEta->seconds);
    }

    public function test_confidence_falls_with_distance(): void
    {
        $trip = $this->tripAt(0, 25.0);

        $near = $this->eta->estimate($trip, $this->stops[1]);
        $far = $this->eta->estimate($trip, $this->stops[4]);

        $this->assertGreaterThan($far->confidence, $near->confidence);
    }

    public function test_stale_telemetry_lowers_confidence(): void
    {
        $fresh = $this->tripAt(0, 25.0);
        $stale = $this->tripAt(0, 25.0);
        $stale->forceFill(['last_ping_at' => now()->subMinutes(10)])->save();

        $freshEta = $this->eta->estimate($fresh, $this->stops[2]);
        $staleEta = $this->eta->estimate($stale->fresh(), $this->stops[2]);

        $this->assertGreaterThan($staleEta->confidence, $freshEta->confidence);
    }

    public function test_historical_segment_times_are_used_once_enough_samples_exist(): void
    {
        $bucket = TimeBucket::of(now());

        // A segment that historically takes far longer than the baseline
        // suggests; with enough samples the estimate must move towards it.
        SegmentTravelStat::create([
            'route_id' => $this->route->id,
            'from_stop_id' => $this->stops[0]->id,
            'to_stop_id' => $this->stops[1]->id,
            'day_type' => $bucket['day_type'],
            'hour_bucket' => $bucket['hour_bucket'],
            'sample_count' => 50,
            'mean_seconds' => 600,
            'traffic_factor' => 1.0,
        ]);

        $trip = $this->tripAt(0, 30.0);
        $trip->forceFill(['current_stop_id' => $this->stops[0]->id])->save();

        $estimate = $this->eta->estimate($trip->fresh(), $this->stops[1]);

        $this->assertSame('blended', $estimate->source);
        // The 600s history pulls the estimate well above the ~120s baseline.
        $this->assertGreaterThan(200, $estimate->seconds);
    }

    public function test_sparse_history_is_ignored(): void
    {
        $bucket = TimeBucket::of(now());

        SegmentTravelStat::create([
            'route_id' => $this->route->id,
            'from_stop_id' => $this->stops[0]->id,
            'to_stop_id' => $this->stops[1]->id,
            'day_type' => $bucket['day_type'],
            'hour_bucket' => $bucket['hour_bucket'],
            // Below the configured minimum: one freak journey must not
            // dominate every future prediction.
            'sample_count' => 2,
            'mean_seconds' => 3000,
            'traffic_factor' => 1.0,
        ]);

        $trip = $this->tripAt(0, 30.0);
        $trip->forceFill(['current_stop_id' => $this->stops[0]->id])->save();

        $estimate = $this->eta->estimate($trip->fresh(), $this->stops[1]);

        $this->assertSame('baseline', $estimate->source);
        $this->assertLessThan(400, $estimate->seconds);
    }

    public function test_recording_observations_builds_a_running_mean(): void
    {
        foreach ([100, 120, 140] as $seconds) {
            $this->eta->recordObservation(
                $this->route->id, $this->stops[0]->id, $this->stops[1]->id, $seconds
            );
        }

        $stat = SegmentTravelStat::first();

        $this->assertSame(3, $stat->sample_count);
        $this->assertEqualsWithDelta(120.0, $stat->mean_seconds, 0.01);
        // Welford's variance for [100,120,140] is 400, so sd = 20.
        $this->assertEqualsWithDelta(20.0, $stat->standardDeviation(), 0.01);
    }

    public function test_implausible_observations_are_discarded(): void
    {
        $this->eta->recordObservation($this->route->id, $this->stops[0]->id, $this->stops[1]->id, 2);
        $this->eta->recordObservation($this->route->id, $this->stops[0]->id, $this->stops[1]->id, 7200);

        $this->assertSame(0, SegmentTravelStat::count());
    }

    public function test_the_arrival_board_lists_approaching_buses_soonest_first(): void
    {
        $near = $this->tripAt(2_700, 25.0);
        $far = $this->tripAt(200, 25.0);

        $arrivals = $this->eta->arrivalsForStop($this->stops[4]);

        $this->assertCount(2, $arrivals);
        $this->assertSame($near->uuid, $arrivals[0]['trip_uuid']);
        $this->assertLessThanOrEqual($arrivals[1]['eta']['seconds'], $arrivals[0]['eta']['seconds']);
    }
}
