<?php

namespace Tests\Feature\Ridership;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\BusQrCode;
use App\Domain\Fleet\Services\BusQrService;
use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\City;
use App\Domain\Operations\Enums\TripStatus;
use App\Domain\Operations\Models\Trip;
use App\Domain\Ridership\Enums\PassengerTripStatus;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Domain\Ridership\Services\BoardingService;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Models\FareRule;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\InsufficientFundsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Boarding is where cryptography, money and concurrency all meet, so it is
 * tested from the outside: a scan either seats and charges exactly once, or it
 * does neither.
 */
class BoardingTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Bus $bus;

    private Trip $trip;

    private BusQrCode $qr;

    private BoardingService $boarding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boarding = app(BoardingService::class);
        $this->city = $this->makeCity();

        $line = BusLine::factory()->create(['city_id' => $this->city->id]);
        $route = BusRoute::factory()->create(['bus_line_id' => $line->id]);

        $this->bus = Bus::factory()->inService()->create(['city_id' => $this->city->id]);
        $this->qr = app(BusQrService::class)->issueFor($this->bus);

        $this->trip = Trip::factory()->create([
            'city_id' => $this->city->id,
            'bus_id' => $this->bus->id,
            'bus_line_id' => $line->id,
            'route_id' => $route->id,
            'status' => TripStatus::Active,
            'current_lat' => 27.1832,
            'current_lng' => 56.2666,
        ]);

        FareRule::factory()->create([
            'city_id' => $this->city->id,
            'base_fare' => 50_000,
            'passenger_type' => 'regular',
        ]);

        RateLimiter::clear('boarding:1');
    }

    private function token(): string
    {
        return app(QrTokenService::class)->issue($this->qr->public_id, $this->qr->secret);
    }

    public function test_a_valid_scan_seats_the_passenger_and_charges_the_fare_once(): void
    {
        $user = $this->makePassenger($this->city, 1_000_000);

        $result = $this->boarding->board($user, $this->token());

        $this->assertSame(50_000, $result['fare']);
        $this->assertSame(950_000, $this->walletOf($user)->fresh()->balance);
        $this->assertSame(1, $this->trip->fresh()->passenger_count);
        $this->assertSame(1, WalletTransaction::where('type', TransactionType::FarePayment)->count());
        $this->assertSame(PassengerTripStatus::Active, $result['passenger_trip']->status);
    }

    public function test_the_same_token_cannot_be_used_twice(): void
    {
        $first = $this->makePassenger($this->city, 1_000_000);
        $second = $this->makePassenger($this->city, 1_000_000);

        $token = $this->token();

        $this->boarding->board($first, $token);

        // The screenshot-sharing attack: one code, two riders.
        try {
            $this->boarding->board($second, $token);
            $this->fail('A replayed token must be rejected.');
        } catch (DomainException $e) {
            $this->assertSame('qr_replayed', $e->errorCode());
        }

        $this->assertSame(1_000_000, $this->walletOf($second)->fresh()->balance);
        $this->assertSame(1, $this->trip->fresh()->passenger_count);
    }

    public function test_a_passenger_cannot_board_the_same_bus_twice(): void
    {
        $user = $this->makePassenger($this->city, 1_000_000);

        $this->boarding->board($user, $this->token());

        try {
            // A fresh, individually valid token — the guard here is the open ride.
            $this->boarding->board($user, $this->token());
            $this->fail('A second boarding on the same trip must be rejected.');
        } catch (DomainException $e) {
            $this->assertContains($e->errorCode(), ['already_on_this_bus', 'reboard_cooldown']);
        }

        $this->assertSame(950_000, $this->walletOf($user)->fresh()->balance);
        $this->assertSame(1, $this->trip->fresh()->passenger_count);
    }

    public function test_a_passenger_with_an_open_ride_elsewhere_cannot_board(): void
    {
        $user = $this->makePassenger($this->city, 1_000_000);

        $otherTrip = Trip::factory()->create(['city_id' => $this->city->id]);

        PassengerTrip::create([
            'user_id' => $user->id,
            'trip_id' => $otherTrip->id,
            'bus_id' => $otherTrip->bus_id,
            'city_id' => $this->city->id,
            'status' => PassengerTripStatus::Active,
            'boarded_at' => now()->subMinutes(5),
        ]);

        $this->expectException(DomainException::class);
        $this->boarding->board($user, $this->token());
    }

    public function test_boarding_a_bus_with_no_active_trip_is_rejected(): void
    {
        $this->trip->forceFill(['status' => TripStatus::Completed])->save();

        $user = $this->makePassenger($this->city, 1_000_000);

        try {
            $this->boarding->board($user, $this->token());
            $this->fail('Boarding a parked bus must be rejected.');
        } catch (DomainException $e) {
            $this->assertSame('no_active_trip', $e->errorCode());
        }

        $this->assertSame(1_000_000, $this->walletOf($user)->fresh()->balance);
    }

    public function test_insufficient_balance_leaves_no_charge_and_no_passenger(): void
    {
        $user = $this->makePassenger($this->city, 10_000);

        $this->expectException(InsufficientFundsException::class);

        try {
            $this->boarding->board($user, $this->token());
        } finally {
            $this->assertSame(10_000, $this->walletOf($user)->fresh()->balance);
            $this->assertSame(0, $this->trip->fresh()->passenger_count);
            $this->assertSame(0, PassengerTrip::count());
        }
    }

    public function test_a_revoked_qr_code_cannot_be_used(): void
    {
        $user = $this->makePassenger($this->city, 1_000_000);
        $token = $this->token();

        // The sticker was photographed, so operations rotated the credential.
        app(BusQrService::class)->regenerate($this->bus, User::factory()->create(), 'photographed');

        try {
            $this->boarding->board($user, $token);
            $this->fail('A revoked code must not board.');
        } catch (DomainException $e) {
            $this->assertSame('qr_revoked', $e->errorCode());
        }

        $this->assertSame(1_000_000, $this->walletOf($user)->fresh()->balance);
    }

    public function test_a_passenger_far_from_the_bus_is_rejected(): void
    {
        $user = $this->makePassenger($this->city, 1_000_000);

        try {
            // Same city, several kilometres away from where the bus actually is.
            $this->boarding->board($user, $this->token(), ['lat' => 27.2500, 'lng' => 56.3500]);
            $this->fail('A remote boarding must be rejected.');
        } catch (DomainException $e) {
            $this->assertSame('too_far_from_bus', $e->errorCode());
        }

        $this->assertSame(1_000_000, $this->walletOf($user)->fresh()->balance);
        $this->assertSame(0, PassengerTrip::count());
    }

    public function test_a_passenger_beside_the_bus_is_accepted(): void
    {
        $user = $this->makePassenger($this->city, 1_000_000);

        $result = $this->boarding->board($user, $this->token(), ['lat' => 27.18325, 'lng' => 56.26665]);

        $this->assertNotNull($result['passenger_trip']->id);
    }

    public function test_a_concession_fare_rule_is_applied(): void
    {
        FareRule::factory()->create([
            'city_id' => $this->city->id,
            'base_fare' => 50_000,
            'multiplier' => 0.5,
            'passenger_type' => 'student',
            'priority' => 20,
        ]);

        $user = $this->makePassenger($this->city, 1_000_000);
        $user->forceFill(['preferences' => ['passenger_type' => 'student']])->save();

        $result = $this->boarding->board($user->fresh(), $this->token());

        $this->assertSame(25_000, $result['fare']);
    }

    public function test_boarding_is_rate_limited_per_passenger(): void
    {
        $user = $this->makePassenger($this->city, 10_000_000);
        $limit = (int) config('wallet.fraud.max_payments_per_minute');

        RateLimiter::clear('boarding:'.$user->id);

        for ($i = 0; $i < $limit; $i++) {
            RateLimiter::hit('boarding:'.$user->id, 60);
        }

        try {
            $this->boarding->board($user, $this->token());
            $this->fail('The rate limiter must stop a burst of scans.');
        } catch (DomainException $e) {
            $this->assertSame('too_many_attempts', $e->errorCode());
        }
    }

    public function test_the_driver_facing_passenger_count_tracks_boardings(): void
    {
        foreach (range(1, 3) as $i) {
            $user = $this->makePassenger($this->city, 1_000_000);
            RateLimiter::clear('boarding:'.$user->id);
            $this->boarding->board($user, $this->token());
        }

        $trip = $this->trip->fresh();

        $this->assertSame(3, $trip->passenger_count);
        $this->assertSame(3, $trip->peak_passenger_count);
        $this->assertSame(150_000, $trip->revenue_minor);
    }
}
