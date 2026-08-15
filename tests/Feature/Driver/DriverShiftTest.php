<?php

namespace Tests\Feature\Driver;

use App\Domain\Fleet\Enums\ShiftStatus;
use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\BusAssignment;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Services\BusQrService;
use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\City;
use App\Domain\Operations\Enums\TripStatus;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Services\TripService;
use App\Domain\Ridership\Enums\PassengerTripStatus;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Support\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shift authorisation is the gate that stops an unapproved or unassigned
 * driver from putting a bus into revenue service.
 */
class DriverShiftTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Bus $bus;

    private Driver $driver;

    private BusRoute $route;

    private TripService $trips;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trips = app(TripService::class);
        $this->city = $this->makeCity();

        $line = BusLine::factory()->create(['city_id' => $this->city->id]);
        $this->route = BusRoute::factory()->create(['bus_line_id' => $line->id]);

        $this->bus = Bus::factory()->create(['city_id' => $this->city->id]);
        app(BusQrService::class)->issueFor($this->bus);

        $this->driver = Driver::factory()->create([
            'user_id' => User::factory()->create(['city_id' => $this->city->id]),
            'city_id' => $this->city->id,
        ]);

        BusAssignment::create([
            'bus_id' => $this->bus->id,
            'driver_id' => $this->driver->id,
            'starts_on' => today()->subMonth(),
            'is_active' => true,
        ]);
    }

    public function test_an_assigned_driver_can_start_a_shift(): void
    {
        $shift = $this->trips->startShift($this->driver, $this->bus);

        $this->assertSame(ShiftStatus::Open, $shift->status);
        $this->assertSame($this->driver->id, $this->bus->fresh()->current_driver_id);
    }

    public function test_an_unassigned_driver_cannot_start_a_shift(): void
    {
        $stranger = Driver::factory()->create([
            'user_id' => User::factory()->create(['city_id' => $this->city->id]),
            'city_id' => $this->city->id,
        ]);

        try {
            $this->trips->startShift($stranger, $this->bus);
            $this->fail('An unassigned driver must not start a shift.');
        } catch (DomainException $e) {
            $this->assertSame('bus_not_assigned', $e->errorCode());
        }
    }

    public function test_a_driver_pending_approval_cannot_start_a_shift(): void
    {
        $this->driver->forceFill(['status' => \App\Domain\Fleet\Enums\DriverStatus::PendingApproval])->save();

        try {
            $this->trips->startShift($this->driver->fresh(), $this->bus);
            $this->fail('An unapproved driver must not start a shift.');
        } catch (DomainException $e) {
            $this->assertSame('driver_not_active', $e->errorCode());
        }
    }

    public function test_an_expired_licence_blocks_a_shift_even_when_assigned(): void
    {
        $this->driver->forceFill(['license_expires_at' => now()->subDay()])->save();

        try {
            $this->trips->startShift($this->driver->fresh(), $this->bus);
            $this->fail('An expired licence must block the shift.');
        } catch (DomainException $e) {
            $this->assertSame('license_expired', $e->errorCode());
        }
    }

    public function test_a_bus_from_another_city_is_refused(): void
    {
        $otherCity = City::factory()->create();
        $foreignBus = Bus::factory()->create(['city_id' => $otherCity->id]);

        BusAssignment::create([
            'bus_id' => $foreignBus->id,
            'driver_id' => $this->driver->id,
            'starts_on' => today()->subMonth(),
            'is_active' => true,
        ]);

        try {
            $this->trips->startShift($this->driver, $foreignBus);
            $this->fail('Cross-city operation must be refused.');
        } catch (DomainException $e) {
            $this->assertSame('city_mismatch', $e->errorCode());
        }
    }

    public function test_two_drivers_cannot_hold_the_same_bus(): void
    {
        $this->trips->startShift($this->driver, $this->bus);

        $second = Driver::factory()->create([
            'user_id' => User::factory()->create(['city_id' => $this->city->id]),
            'city_id' => $this->city->id,
        ]);

        BusAssignment::create([
            'bus_id' => $this->bus->id,
            'driver_id' => $second->id,
            'starts_on' => today()->subMonth(),
            'is_active' => true,
        ]);

        try {
            $this->trips->startShift($second, $this->bus);
            $this->fail('A bus must host only one open shift.');
        } catch (DomainException $e) {
            $this->assertSame('bus_already_in_service', $e->errorCode());
        }
    }

    public function test_starting_a_shift_twice_returns_the_existing_one(): void
    {
        $first = $this->trips->startShift($this->driver, $this->bus);
        $second = $this->trips->startShift($this->driver, $this->bus);

        $this->assertSame($first->id, $second->id);
    }

    public function test_a_bus_cannot_run_two_trips_at_once(): void
    {
        $shift = $this->trips->startShift($this->driver, $this->bus);
        $this->trips->start($shift, $this->route);

        try {
            $this->trips->start($shift, $this->route);
            $this->fail('A bus must not be on two trips.');
        } catch (DomainException $e) {
            $this->assertSame('bus_already_on_trip', $e->errorCode());
        }
    }

    public function test_ending_a_shift_completes_the_trip_and_frees_the_bus(): void
    {
        $shift = $this->trips->startShift($this->driver, $this->bus);
        $trip = $this->trips->start($shift, $this->route);

        $this->trips->endShift($shift);

        $this->assertSame(TripStatus::Completed, $trip->fresh()->status);
        $this->assertSame(ShiftStatus::Closed, $shift->fresh()->status);
        $this->assertNull($this->bus->fresh()->current_driver_id);
        $this->assertNull($this->bus->fresh()->current_trip_id);
    }

    public function test_completing_a_trip_closes_every_passenger_still_aboard(): void
    {
        $shift = $this->trips->startShift($this->driver, $this->bus);
        $trip = $this->trips->start($shift, $this->route);

        $passengerTrips = collect(range(1, 3))->map(fn () => PassengerTrip::create([
            'user_id' => User::factory()->create()->id,
            'trip_id' => $trip->id,
            'bus_id' => $this->bus->id,
            'city_id' => $this->city->id,
            'status' => PassengerTripStatus::Active,
            'boarded_at' => now()->subMinutes(10),
        ]));

        $trip->forceFill(['passenger_count' => 3])->save();

        $this->trips->complete($trip->fresh());

        foreach ($passengerTrips as $passengerTrip) {
            $this->assertFalse(
                $passengerTrip->fresh()->isOpen(),
                'No passenger may be left on a finished trip.',
            );
        }

        $this->assertSame(0, $trip->fresh()->passenger_count);
    }

    public function test_the_driver_api_reports_shift_and_assignment_state(): void
    {
        $this->actingAsDriver($this->driver->user);

        $this->getJson('/api/v1/driver/state')
            ->assertOk()
            ->assertJsonPath('data.driver.uuid', $this->driver->uuid)
            ->assertJsonCount(1, 'data.assigned_buses')
            ->assertJsonPath('data.shift', null);
    }

    public function test_the_driver_api_starts_a_shift_from_a_scanned_code(): void
    {
        $qr = $this->bus->activeQrCode;
        $token = app(QrTokenService::class)->issue($qr->public_id, $qr->secret);

        $this->actingAsDriver($this->driver->user);

        $this->postJson('/api/v1/driver/shifts/start', ['token' => $token])
            ->assertStatus(201)
            ->assertJsonPath('data.shift.status', 'open')
            ->assertJsonPath('data.bus.bus_number', $this->bus->bus_number);
    }

    /**
     * A trip must start with no recorded ping. Stamping one at creation makes
     * the ingest throttle discard the driver's very first report, which leaves
     * the bus invisible on the live map for the first few seconds of service.
     */
    public function test_a_new_trip_accepts_the_drivers_very_first_location_report(): void
    {
        $shift = $this->trips->startShift($this->driver, $this->bus);
        $trip = $this->trips->start($shift, $this->route);

        $this->assertNull($trip->last_ping_at, 'A trip begins with no telemetry.');

        $result = app(\App\Domain\Operations\Services\LocationIngestService::class)->ingest(
            $trip->fresh(['route', 'bus', 'line', 'nextStop']),
            \App\Domain\Operations\DTO\LocationPing::fromArray([
                'lat' => 27.1832, 'lng' => 56.2666, 'speed' => 25, 'accuracy' => 8,
            ]),
        );

        $this->assertTrue($result['accepted'], 'The first ping must never be throttled.');
        $this->assertNotNull($trip->fresh()->last_ping_at);
    }

    public function test_a_passenger_token_cannot_reach_driver_endpoints(): void
    {
        // The ability gate, not a role check: this is the boundary that keeps
        // a stolen passenger token from operating a bus.
        $this->actingAsPassenger($this->driver->user);

        $this->getJson('/api/v1/driver/state')->assertStatus(403);
    }
}
