<?php

namespace Tests\Feature\Taxi;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiAssignment;
use App\Domain\Taxi\Models\TaxiLine;
use App\Domain\Taxi\Models\TaxiQrCode;
use App\Domain\Taxi\Models\TaxiTariff;
use App\Domain\Taxi\Services\TaxiQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The taxi endpoints, exercised the way the three apps drive them.
 *
 * The line these tests hold is the one between the two live feeds: a passenger
 * asking what is near them gets a car and its mode, and an operations room
 * asking about the city gets the plate and the driver. Publishing the second
 * to the first would turn a "find me a taxi" feature into a tracking service
 * for taxi drivers.
 */
class TaxiApiTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Taxi $taxi;

    private Driver $driver;

    private TaxiQrCode $qr;

    private TaxiLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->line = TaxiLine::factory()->create(['city_id' => $this->city->id, 'flat_fare' => 150_000]);

        $this->driver = Driver::factory()->create(['city_id' => $this->city->id]);
        $this->taxi = Taxi::factory()->inService()->create([
            'city_id' => $this->city->id,
            'default_taxi_line_id' => $this->line->id,
            'last_lat' => 27.1832,
            'last_lng' => 56.2666,
        ]);

        TaxiAssignment::create([
            'taxi_id' => $this->taxi->id,
            'driver_id' => $this->driver->id,
            'starts_on' => today(),
            'is_active' => true,
        ]);

        $this->qr = app(TaxiQrService::class)->issueFor($this->taxi);
    }

    private function token(): string
    {
        return app(QrTokenService::class)->issue($this->qr->public_id, $this->qr->secret);
    }

    private function actingAsTaxiDriver(): static
    {
        Sanctum::actingAs($this->driver->user, ['taxi_driver', 'passenger']);

        return $this;
    }

    private function startShift(string $mode = 'line'): array
    {
        return $this->actingAsTaxiDriver()
            ->postJson('/api/v1/taxi/driver/shifts/start', [
                'taxi_uuid' => $this->taxi->uuid,
                'service_type' => $mode,
                'taxi_line_id' => $mode === 'line' ? $this->line->id : null,
                'lat' => 27.1832,
                'lng' => 56.2666,
            ])->assertCreated()->json('data');
    }

    private function passenger(int $balance = 2_000_000): User
    {
        $user = $this->makePassenger($this->city, $balance);
        RateLimiter::clear('taxi-ride:'.$user->id);

        return $user;
    }

    // ── the driver's app ────────────────────────────────────────────────────

    public function test_a_driver_sees_the_cars_they_may_take_and_no_others(): void
    {
        $stranger = Taxi::factory()->inService()->create(['city_id' => $this->city->id]);

        $data = $this->actingAsTaxiDriver()->getJson('/api/v1/taxi/driver/state')->assertOk()->json('data');

        $this->assertCount(1, $data['assigned_taxis']);
        $this->assertSame($this->taxi->uuid, $data['assigned_taxis'][0]['taxi']['uuid']);
        $this->assertNotSame($stranger->uuid, $data['assigned_taxis'][0]['taxi']['uuid']);
        $this->assertNull($data['shift']);
    }

    public function test_starting_a_shift_hands_back_the_rotating_code(): void
    {
        $data = $this->startShift('line');

        $this->assertSame('line', $data['shift']['service_type']);
        $this->assertNotEmpty($data['qr']['token']);
        // The code rotates on its own; a static one could be photographed once
        // and used from anywhere.
        $this->assertGreaterThan(0, $data['qr']['expires_in']);
    }

    public function test_a_passenger_token_cannot_reach_the_taxi_driver_endpoints(): void
    {
        $this->actingAsPassenger($this->passenger());

        $this->getJson('/api/v1/taxi/driver/state')->assertForbidden();
        $this->postJson('/api/v1/taxi/driver/shifts/start', [])->assertForbidden();
    }

    public function test_the_driver_names_a_charter_price_and_it_expires_on_its_own(): void
    {
        $this->startShift('charter');

        $this->actingAsTaxiDriver()
            ->postJson('/api/v1/taxi/driver/charter', ['amount' => 900_000])
            ->assertOk()
            ->assertJsonPath('data.pending_charter_amount', 900_000);

        $this->travel((int) config('taxi.charter.quote_ttl_seconds') + 60)->seconds();

        $this->actingAsTaxiDriver()
            ->getJson('/api/v1/taxi/driver/state')
            ->assertOk()
            ->assertJsonPath('data.shift.pending_charter_amount', null);
    }

    public function test_a_location_report_advances_the_meter_and_answers_with_the_fare(): void
    {
        TaxiTariff::factory()->create([
            'city_id' => $this->city->id,
            'service_type' => TaxiServiceType::Meter,
        ]);

        $this->startShift('meter');

        $passenger = $this->passenger();
        $this->actingAsPassenger($passenger)
            ->postJson('/api/v1/taxi/rides', ['token' => $this->token()])
            ->assertCreated();

        $first = $this->actingAsTaxiDriver()->postJson('/api/v1/taxi/driver/location', [
            'lat' => 27.1832, 'lng' => 56.2666, 'speed' => 30, 'accuracy' => 10,
        ])->assertOk();

        $this->travel(60)->seconds();

        $second = $this->actingAsTaxiDriver()->postJson('/api/v1/taxi/driver/location', [
            'lat' => 27.1922, 'lng' => 56.2666, 'speed' => 30, 'accuracy' => 10,
        ])->assertOk();

        $this->assertTrue($first->json('data.accepted'));
        $this->assertGreaterThan(0, $second->json('data.current_fare.amount'));
        $this->assertGreaterThan(500, $second->json('data.ride.distance_meters'));
    }

    // ── the passenger's app ─────────────────────────────────────────────────

    public function test_a_scan_prices_the_ride_without_taking_it(): void
    {
        $this->startShift('line');
        $passenger = $this->passenger();

        $this->actingAsPassenger($passenger)
            ->postJson('/api/v1/taxi/scan', ['token' => $this->token()])
            ->assertOk()
            ->assertJsonPath('data.quote.amount', 150_000)
            ->assertJsonPath('data.requires_amount_confirmation', true);

        // Nothing moved: a scan is a question, not a payment.
        $this->assertSame(2_000_000, $this->walletOf($passenger)->balance);
    }

    public function test_taking_the_ride_charges_the_quoted_fare(): void
    {
        $this->startShift('line');
        $passenger = $this->passenger();

        $this->actingAsPassenger($passenger)
            ->postJson('/api/v1/taxi/rides', [
                'token' => $this->token(),
                'accepted_amount' => 150_000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.fare_amount', 150_000);

        $this->assertSame(1_850_000, $this->walletOf($passenger)->balance);
    }

    public function test_an_amount_the_passenger_never_saw_cannot_be_charged(): void
    {
        $this->startShift('line');
        $passenger = $this->passenger();

        $this->actingAsPassenger($passenger)
            ->postJson('/api/v1/taxi/rides', [
                'token' => $this->token(),
                'accepted_amount' => 15_000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'amount_mismatch');

        $this->assertSame(2_000_000, $this->walletOf($passenger)->balance);
    }

    public function test_the_active_ride_carries_the_meter_reading(): void
    {
        TaxiTariff::factory()->create([
            'city_id' => $this->city->id,
            'service_type' => TaxiServiceType::Meter,
        ]);

        $this->startShift('meter');
        $passenger = $this->passenger();

        $this->actingAsPassenger($passenger)
            ->postJson('/api/v1/taxi/rides', ['token' => $this->token()])
            ->assertCreated();

        // A price that only appears at the destination is a surprise, not a
        // price, so the running figure travels with the ride.
        $this->actingAsPassenger($passenger)
            ->getJson('/api/v1/taxi/rides/active')
            ->assertOk()
            ->assertJsonPath('data.service_type', 'meter')
            ->assertJsonStructure(['data' => ['current_fare' => ['amount', 'breakdown']]]);
    }

    public function test_another_passengers_ride_cannot_be_ended(): void
    {
        $this->startShift('line');

        $rider = $this->passenger();
        $uuid = $this->actingAsPassenger($rider)
            ->postJson('/api/v1/taxi/rides', ['token' => $this->token(), 'accepted_amount' => 150_000])
            ->assertCreated()
            ->json('data.uuid');

        $this->actingAsPassenger($this->passenger())
            ->postJson("/api/v1/taxi/rides/$uuid/end")
            ->assertNotFound();
    }

    // ── the two live feeds ──────────────────────────────────────────────────

    public function test_the_public_feed_needs_a_position_and_names_nobody(): void
    {
        $this->startShift('line');
        $this->actingAsTaxiDriver()->postJson('/api/v1/taxi/driver/location', [
            'lat' => 27.1832, 'lng' => 56.2666,
        ])->assertOk();

        $this->getJson('/api/v1/taxis/nearby')->assertStatus(422);

        $row = $this->getJson('/api/v1/taxis/nearby?lat=27.1832&lng=56.2666&radius=1000')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('line', $row['service_type']);
        $this->assertArrayHasKey('distance_meters', $row);
        // The things a rider does not need and a driver should not have published.
        $this->assertArrayNotHasKey('plate', $row);
        $this->assertArrayNotHasKey('driver_id', $row);
        $this->assertArrayNotHasKey('onboard_count', $row);
    }

    public function test_the_public_feed_only_reaches_as_far_as_it_is_allowed_to(): void
    {
        $this->startShift('line');
        $this->actingAsTaxiDriver()->postJson('/api/v1/taxi/driver/location', [
            'lat' => 27.1832, 'lng' => 56.2666,
        ])->assertOk();

        // Twenty kilometres away. A radius the caller invents does not widen
        // the one the server will answer.
        $this->getJson('/api/v1/taxis/nearby?lat=27.3832&lng=56.2666&radius=5000')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_operations_feed_carries_what_a_dispatcher_needs(): void
    {
        $this->startShift('line');
        $this->actingAsTaxiDriver()->postJson('/api/v1/taxi/driver/location', [
            'lat' => 27.1832, 'lng' => 56.2666,
        ])->assertOk();

        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]));

        $response = $this->getJson('/api/v1/admin/taxi/live')->assertOk();

        $this->assertSame($this->taxi->plate, $response->json('data.0.plate'));
        $this->assertSame(1, $response->json('meta.by_service_type.line'));
    }

    public function test_a_support_agent_cannot_see_the_operations_feed(): void
    {
        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]), Role::SUPPORT_AGENT);

        $this->getJson('/api/v1/admin/taxi/live')->assertForbidden();
        $this->getJson('/api/v1/admin/taxi/taxis')->assertForbidden();
    }

    // ── the admin panel ─────────────────────────────────────────────────────

    public function test_a_new_taxi_gets_a_code_without_a_second_click(): void
    {
        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]));

        $uuid = $this->postJson('/api/v1/admin/taxi/taxis', [
            'taxi_number' => '7788',
            'plate' => '12 ب 345 ایران ۸۴',
            'capacity' => 4,
        ])->assertCreated()->json('data.uuid');

        // A taxi with no code cannot take a fare, and leaving that to a second
        // click is how cars reach the street mute.
        $this->getJson("/api/v1/admin/taxi/taxis/$uuid/qr")
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'public_id', 'expires_in']]);
    }

    public function test_regenerating_a_code_kills_the_old_one(): void
    {
        $this->startShift('line');
        $stale = $this->token();

        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]));
        $this->postJson("/api/v1/admin/taxi/taxis/{$this->taxi->uuid}/qr/regenerate", [
            'reason' => 'sticker photographed',
        ])->assertOk();

        $this->actingAsPassenger($this->passenger())
            ->postJson('/api/v1/taxi/scan', ['token' => $stale])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'qr_revoked');
    }

    public function test_another_citys_taxi_is_out_of_reach(): void
    {
        $other = City::factory()->create(['slug' => 'shiraz']);
        $stranger = Taxi::factory()->create(['city_id' => $other->id]);

        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]));

        $this->patchJson("/api/v1/admin/taxi/taxis/{$stranger->uuid}", ['model' => 'x'])->assertNotFound();
    }

    public function test_the_report_splits_the_three_products(): void
    {
        $this->startShift('line');
        $this->actingAsPassenger($this->passenger())
            ->postJson('/api/v1/taxi/rides', ['token' => $this->token(), 'accepted_amount' => 150_000])
            ->assertCreated();

        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]));

        $modes = collect($this->getJson('/api/v1/admin/taxi/report')->assertOk()->json('data.by_service_type'))
            ->keyBy('service_type');

        $this->assertSame(3, $modes->count());
        $this->assertSame(150_000, $modes['line']['gross']);
        $this->assertSame(0, $modes['meter']['gross']);
    }

    /**
     * The report reads through to the line and the driver behind each ride.
     *
     * It was fine on an empty day and threw the moment there was anything to
     * report, because those two groupings lazy-load — which is why this asserts
     * the named rows rather than only the totals.
     */
    public function test_the_report_names_the_busiest_lines_and_the_top_drivers(): void
    {
        $this->startShift('line');

        foreach (range(1, 2) as $ignored) {
            $this->actingAsPassenger($this->passenger())
                ->postJson('/api/v1/taxi/rides', ['token' => $this->token(), 'accepted_amount' => 150_000])
                ->assertCreated();
        }

        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]));

        $report = $this->getJson('/api/v1/admin/taxi/report')->assertOk();

        $this->assertSame($this->line->code, $report->json('data.busiest_lines.0.line'));
        $this->assertSame(2, $report->json('data.busiest_lines.0.ride_count'));
        $this->assertSame($this->driver->user->name, $report->json('data.top_drivers.0.driver'));
        $this->assertSame(300_000, $report->json('data.gross'));
    }
}
