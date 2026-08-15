<?php

namespace Tests\Feature\Ridership;

use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use App\Domain\Network\Models\City;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Services\RouteMatcher;
use App\Domain\Ridership\Enums\AlightingSource;
use App\Domain\Ridership\Enums\PassengerTripStatus;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Domain\Ridership\Services\AlightingService;
use App\Support\Geo\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alighting detection has to survive ordinary urban GPS noise. The property
 * being defended here is asymmetric: wrongly ending a ride is much worse than
 * ending it a little late, because the seat is freed while the passenger is
 * still aboard and the driver's count goes wrong.
 */
class AlightingTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Trip $trip;

    private User $user;

    private PassengerTrip $ride;

    private AlightingService $alighting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alighting = app(AlightingService::class);
        $this->city = $this->makeCity();

        $line = BusLine::factory()->create(['city_id' => $this->city->id]);

        $route = BusRoute::factory()->create([
            'bus_line_id' => $line->id,
            'geometry' => [
                ['lat' => 27.150, 'lng' => 56.250],
                ['lat' => 27.186, 'lng' => 56.250],
            ],
        ]);

        foreach (range(0, 2) as $index) {
            $stop = BusStop::factory()->at(27.150 + $index * 0.015, 56.250)->create([
                'city_id' => $this->city->id,
            ]);

            $route->routeStops()->create(['bus_stop_id' => $stop->id, 'sequence' => $index + 1]);
        }

        app(RouteMatcher::class)->recalculateStopOffsets($route);

        $this->trip = Trip::factory()->create([
            'city_id' => $this->city->id,
            'bus_line_id' => $line->id,
            'route_id' => $route->id,
            'current_lat' => 27.165,
            'current_lng' => 56.250,
            'current_speed_kmh' => 25.0,
            'route_offset_meters' => 1_660,
            'passenger_count' => 1,
        ]);

        $this->user = User::factory()->create(['city_id' => $this->city->id]);

        $this->ride = PassengerTrip::create([
            'user_id' => $this->user->id,
            'trip_id' => $this->trip->id,
            'bus_id' => $this->trip->bus_id,
            'city_id' => $this->city->id,
            'route_id' => $route->id,
            'status' => PassengerTripStatus::Active,
            'boarded_at' => now()->subMinutes(8),
        ]);
    }

    /** A position a few metres from the bus: the passenger is aboard. */
    public function test_a_passenger_sitting_on_the_bus_stays_aboard(): void
    {
        $result = $this->alighting->observe($this->ride, new Coordinate(27.16505, 56.25002), 10);

        $this->assertFalse($result['closed']);
        $this->assertSame(PassengerTripStatus::Active->value, $result['status']);
        $this->assertSame(1, $this->trip->fresh()->passenger_count);
    }

    public function test_gps_noise_alone_does_not_end_a_ride(): void
    {
        // 80 m away, reported with 60 m accuracy: this is everyday city GPS,
        // not evidence of anything.
        $result = $this->alighting->observe($this->ride, new Coordinate(27.16572, 56.250), 60);

        $this->assertFalse($result['closed']);
        $this->assertSame(1, $this->trip->fresh()->passenger_count);
    }

    public function test_a_single_distant_reading_only_marks_the_ride_pending(): void
    {
        // Far away and accurate — suggestive, but one sample is not a decision.
        $result = $this->alighting->observe($this->ride, new Coordinate(27.1600, 56.250), 5);

        $this->assertFalse($result['closed']);
        $this->assertSame(PassengerTripStatus::PendingAlighting->value, $result['status']);
        $this->assertSame(1, $this->trip->fresh()->passenger_count, 'The seat stays occupied until confirmed.');
    }

    /**
     * The ride closes only once two separate observations each clear the
     * confidence threshold. Note the first sample can never be one of them:
     * with nothing to compare against it scores no divergence, which is
     * exactly the conservatism being tested for.
     */
    public function test_the_ride_closes_once_two_observations_clear_the_threshold(): void
    {
        $threshold = (float) config('transit.ridership.alighting_confidence_threshold');

        // Walking steadily away from a bus that is driving on.
        $first = $this->alighting->observe($this->ride, new Coordinate(27.1600, 56.250), 5);
        $this->assertLessThan($threshold, $first['confidence'], 'A lone first sample must not be conclusive.');
        $this->assertFalse($first['closed']);

        $second = $this->alighting->observe($this->ride->fresh(), new Coordinate(27.1590, 56.250), 5);
        $this->assertGreaterThanOrEqual($threshold, $second['confidence']);
        $this->assertFalse($second['closed'], 'One confident sample is still only one.');

        $third = $this->alighting->observe($this->ride->fresh(), new Coordinate(27.1570, 56.250), 5);

        $this->assertTrue($third['closed']);
        $this->assertSame(PassengerTripStatus::Completed, $this->ride->fresh()->status);
        $this->assertSame(0, $this->trip->fresh()->passenger_count);
    }

    public function test_returning_close_to_the_bus_clears_a_pending_state(): void
    {
        $this->alighting->observe($this->ride, new Coordinate(27.1600, 56.250), 5);
        $this->assertSame(PassengerTripStatus::PendingAlighting, $this->ride->fresh()->status);

        // The passenger was never off: a bad fix, now corrected.
        $this->alighting->observe($this->ride->fresh(), new Coordinate(27.16502, 56.250), 5);

        $this->assertSame(PassengerTripStatus::Active, $this->ride->fresh()->status);
        $this->assertSame(1, $this->trip->fresh()->passenger_count);
    }

    public function test_the_passenger_can_end_the_ride_themselves(): void
    {
        $ride = $this->alighting->closeManually($this->ride, new Coordinate(27.165, 56.250));

        $this->assertSame(PassengerTripStatus::Completed, $ride->status);
        $this->assertSame(AlightingSource::Manual->value, $ride->alighting_source);
        $this->assertSame(1.0, $ride->alighting_confidence);
        $this->assertSame(0, $this->trip->fresh()->passenger_count);
    }

    public function test_closing_a_ride_twice_does_not_double_count(): void
    {
        $this->alighting->closeManually($this->ride);
        $this->alighting->closeManually($this->ride->fresh());

        $this->assertSame(0, $this->trip->fresh()->passenger_count, 'The counter must not go negative.');
    }

    public function test_the_ride_records_its_duration_when_closed(): void
    {
        $ride = $this->alighting->closeManually($this->ride);

        $this->assertGreaterThanOrEqual(480, $ride->duration_seconds);
        $this->assertNotNull($ride->alighted_at);
    }

    public function test_abandoned_rides_are_force_closed_by_the_scheduler(): void
    {
        $this->ride->forceFill([
            'boarded_at' => now()->subHours(3),
        ])->save();

        $closed = $this->alighting->closeAbandoned();

        $this->assertSame(1, $closed);
        $this->assertSame(PassengerTripStatus::ForceClosed, $this->ride->fresh()->status);
        $this->assertSame(AlightingSource::Timeout->value, $this->ride->fresh()->alighting_source);
    }

    public function test_a_recent_ride_is_not_force_closed(): void
    {
        $this->assertSame(0, $this->alighting->closeAbandoned());
        $this->assertTrue($this->ride->fresh()->isOpen());
    }

    public function test_every_detection_records_the_signals_that_produced_it(): void
    {
        $this->alighting->observe($this->ride, new Coordinate(27.1600, 56.250), 5);

        $detection = $this->ride->alightings()->first();

        $this->assertNotNull($detection);
        // A disputed charge must be reconstructable from the stored evidence.
        $this->assertArrayHasKey('separation_score', $detection->signals);
        $this->assertArrayHasKey('divergence_score', $detection->signals);
        $this->assertArrayHasKey('effective_separation', $detection->signals);
    }

    public function test_the_passenger_api_ends_a_ride(): void
    {
        $this->actingAsPassenger($this->user);

        $this->postJson("/api/v1/rides/{$this->ride->uuid}/end", [
            'lat' => 27.165, 'lng' => 56.250,
        ])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_a_passenger_cannot_end_someone_elses_ride(): void
    {
        $this->actingAsPassenger(User::factory()->create());

        $this->postJson("/api/v1/rides/{$this->ride->uuid}/end")->assertStatus(404);
    }
}
