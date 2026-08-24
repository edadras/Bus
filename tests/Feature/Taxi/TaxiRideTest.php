<?php

namespace Tests\Feature\Taxi;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\Taxi\Enums\TaxiRideStatus;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiAssignment;
use App\Domain\Taxi\Models\TaxiLine;
use App\Domain\Taxi\Models\TaxiQrCode;
use App\Domain\Taxi\Models\TaxiShift;
use App\Domain\Taxi\Models\TaxiTariff;
use App\Domain\Taxi\Services\TaxiMeterService;
use App\Domain\Taxi\Services\TaxiQrService;
use App\Domain\Taxi\Services\TaxiRideService;
use App\Domain\Taxi\Services\TaxiShiftService;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\LedgerService;
use App\Domain\Wallet\Services\WalletService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\InsufficientFundsException;
use App\Support\Exceptions\QrValidationException;
use App\Support\Geo\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The three taxi products, tested where they differ: when money moves.
 *
 * A line fare is paid on boarding at a published price, a charter at the price
 * the driver named, and a meter only when the ride ends — and that last one is
 * the only charge on the platform whose size nobody knows in advance, which is
 * why most of these tests are about it.
 */
class TaxiRideTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Taxi $taxi;

    private Driver $driver;

    private TaxiQrCode $qr;

    private TaxiLine $line;

    private TaxiRideService $rides;

    private TaxiShiftService $shifts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rides = app(TaxiRideService::class);
        $this->shifts = app(TaxiShiftService::class);

        $this->city = $this->makeCity();
        $this->line = TaxiLine::factory()->create(['city_id' => $this->city->id, 'flat_fare' => 150_000]);

        $this->driver = Driver::factory()->create(['city_id' => $this->city->id]);
        $this->taxi = Taxi::factory()->inService()->create([
            'city_id' => $this->city->id,
            'commission_bps' => 1000,
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

    private function openShift(TaxiServiceType $mode): TaxiShift
    {
        return $this->shifts->open($this->driver, $this->taxi->fresh(), $mode, $mode === TaxiServiceType::Line ? $this->line : null);
    }

    private function passenger(int $balance = 1_000_000): User
    {
        $user = $this->makePassenger($this->city, $balance);
        RateLimiter::clear('taxi-ride:'.$user->id);

        return $user;
    }

    private function meterTariff(array $overrides = []): TaxiTariff
    {
        return TaxiTariff::factory()->create(array_merge([
            'city_id' => $this->city->id,
            'service_type' => TaxiServiceType::Meter,
        ], $overrides));
    }

    /**
     * Assert an operation is refused for a specific reason.
     *
     * On the code, never on the message: the message is translated prose that
     * a copy edit is free to change, and a test that breaks when somebody
     * improves a sentence is a test nobody trusts.
     */
    private function assertRefused(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected the operation to be refused with [$code].");
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    // ── shared line ─────────────────────────────────────────────────────────

    public function test_a_line_ride_charges_the_published_flat_fare(): void
    {
        $this->openShift(TaxiServiceType::Line);
        $passenger = $this->passenger();

        $quote = $this->rides->quote($passenger, $this->token())['quote'];

        $this->assertSame(150_000, $quote->amount);

        $ride = $this->rides->confirm($passenger, $this->token(), 150_000);

        $this->assertSame(150_000, $ride->fare_amount);
        $this->assertSame(TaxiRideStatus::Active, $ride->status);
        $this->assertSame(850_000, $this->walletOf($passenger)->balance);
    }

    public function test_the_fare_reaches_the_driver_net_of_commission(): void
    {
        $this->openShift(TaxiServiceType::Line);
        $passenger = $this->passenger();

        $this->rides->confirm($passenger, $this->token(), 150_000);

        // 10% of 150,000 is withheld; the rest is the driver's immediately,
        // which is what makes a payout a transfer rather than a calculation.
        $this->assertSame(135_000, $this->walletOf($this->driver->user)->balance);

        $transaction = WalletTransaction::latest('id')->firstOrFail();
        $this->assertSame(TransactionType::TaxiFare, $transaction->type);
    }

    public function test_a_full_car_boards_together_rather_than_queueing_for_the_code(): void
    {
        $this->openShift(TaxiServiceType::Line);
        $token = $this->token();

        $first = $this->passenger();
        $second = $this->passenger();

        $this->rides->confirm($first, $token, 150_000);
        $this->rides->confirm($second, $token, 150_000);

        // Four people get into a shared taxi at once. Making them wait thirty
        // seconds each for the code to rotate would be an invented obstacle.
        $this->assertSame(2, $this->taxi->fresh()->shifts()->first()->onboard_count);
    }

    public function test_one_passenger_cannot_pay_twice_with_the_same_code(): void
    {
        $this->openShift(TaxiServiceType::Line);
        $passenger = $this->passenger();
        $token = $this->token();

        $this->rides->confirm($passenger, $token, 150_000);
        $this->rides->end($this->rides->activeRideFor($passenger));

        $this->expectException(QrValidationException::class);
        $this->rides->confirm($passenger, $token, 150_000);
    }

    public function test_getting_out_frees_the_seat_and_counts_the_alighting(): void
    {
        $shift = $this->openShift(TaxiServiceType::Line);
        $passenger = $this->passenger();

        $this->rides->confirm($passenger, $this->token(), 150_000);
        $this->rides->end($this->rides->activeRideFor($passenger));

        $shift->refresh();

        $this->assertSame(1, $shift->boarding_count);
        $this->assertSame(1, $shift->alighting_count);
        $this->assertSame(0, $shift->onboard_count);
    }

    // ── charter ─────────────────────────────────────────────────────────────

    public function test_a_charter_charges_the_price_the_driver_named(): void
    {
        $shift = $this->openShift(TaxiServiceType::Charter);
        $this->shifts->setCharterAmount($shift, 800_000);

        $passenger = $this->passenger(1_000_000);
        $ride = $this->rides->confirm($passenger, $this->token(), 800_000);

        $this->assertSame(800_000, $ride->fare_amount);
        $this->assertSame(200_000, $this->walletOf($passenger)->balance);
    }

    public function test_a_charter_with_no_price_named_cannot_be_scanned(): void
    {
        $this->openShift(TaxiServiceType::Charter);

        $this->assertRefused('no_charter_amount_set', fn () => $this->rides->quote($this->passenger(), $this->token()));
    }

    public function test_a_confirmation_carrying_a_different_amount_is_refused(): void
    {
        $shift = $this->openShift(TaxiServiceType::Charter);
        $this->shifts->setCharterAmount($shift, 800_000);

        $passenger = $this->passenger();

        // The client may echo the price back; it may never set one.
        $this->assertRefused('amount_mismatch', fn () => $this->rides->confirm($passenger, $this->token(), 80_000));

        $this->assertSame(1_000_000, $this->walletOf($passenger)->balance);
    }

    public function test_a_named_price_belongs_to_one_hire_only(): void
    {
        $shift = $this->openShift(TaxiServiceType::Charter);
        $this->shifts->setCharterAmount($shift, 800_000);

        $first = $this->passenger();
        $this->rides->confirm($first, $this->token(), 800_000);
        $this->rides->end($this->rides->activeRideFor($first));

        $second = $this->passenger();

        $this->assertRefused('no_charter_amount_set', fn () => $this->rides->quote($second, $this->token()));
    }

    public function test_a_stale_price_is_not_offered_to_the_next_passenger(): void
    {
        $shift = $this->openShift(TaxiServiceType::Charter);
        $this->shifts->setCharterAmount($shift, 800_000);

        // The hire this price was named for drove away twenty minutes ago.
        $this->travel((int) config('taxi.charter.quote_ttl_seconds') + 60)->seconds();

        $this->assertRefused('no_charter_amount_set', fn () => $this->rides->quote($this->passenger(), $this->token()));
    }

    // ── meter ───────────────────────────────────────────────────────────────

    public function test_a_meter_charges_nothing_until_the_ride_ends(): void
    {
        $this->meterTariff();
        $this->openShift(TaxiServiceType::Meter);

        $passenger = $this->passenger();
        $ride = $this->rides->confirm($passenger, $this->token());

        $this->assertSame(0, $ride->fare_amount);
        $this->assertSame(1_000_000, $this->walletOf($passenger)->balance);
    }

    public function test_a_meter_will_not_start_on_a_wallet_that_cannot_cover_it(): void
    {
        $this->meterTariff(['minimum_fare' => 300_000]);
        $this->openShift(TaxiServiceType::Meter);

        // Refusing at the kerb is a small annoyance. Stopping the car at the
        // destination to argue about an empty wallet is not.
        $this->expectException(InsufficientFundsException::class);
        $this->rides->quote($this->passenger(100_000), $this->token());
    }

    public function test_distance_and_waiting_are_billed_separately(): void
    {
        $tariff = $this->meterTariff();
        $shift = $this->openShift(TaxiServiceType::Meter);

        $passenger = $this->passenger(5_000_000);
        $ride = $this->rides->confirm($passenger, $this->token());

        $meter = app(TaxiMeterService::class);

        // Two kilometres of driving, then two minutes at a light.
        $meter->accumulate($ride, new Coordinate(27.1832, 56.2666), speedKmh: 30, recordedAt: now());
        $this->travel(120)->seconds();
        $meter->accumulate($ride->fresh(), new Coordinate(27.2012, 56.2666), speedKmh: 30, recordedAt: now());
        $this->travel(120)->seconds();
        $meter->accumulate($ride->fresh(), new Coordinate(27.2012, 56.2666), speedKmh: 0, recordedAt: now());

        $quote = $meter->currentQuote($ride->fresh());

        $this->assertGreaterThan(1900, $ride->fresh()->distance_meters);
        $this->assertSame(120, $ride->fresh()->waiting_seconds);
        // base 200,000 + ~2 km at 100,000 + 2 min waiting at 20,000
        $this->assertSame(200_000, $quote->breakdown['base_fare']);
        $this->assertSame(40_000, $quote->breakdown['waiting_component']);
        $this->assertGreaterThan(190_000, $quote->breakdown['distance_component']);
        $this->assertSame($tariff->id, $ride->fresh()->taxi_tariff_id);
    }

    public function test_a_short_hop_is_charged_the_minimum_fare(): void
    {
        $this->meterTariff(['minimum_fare' => 300_000]);
        $this->openShift(TaxiServiceType::Meter);

        $passenger = $this->passenger(5_000_000);
        $ride = $this->rides->confirm($passenger, $this->token());

        $ended = $this->rides->end($ride, 'passenger');

        $this->assertSame(300_000, $ended->fare_amount);
        $this->assertTrue($ended->fare_breakdown['minimum_applied']);
    }

    public function test_a_metered_ride_the_wallet_cannot_cover_becomes_a_recorded_debt(): void
    {
        $this->meterTariff(['minimum_fare' => 300_000, 'base_fare' => 200_000]);
        $this->openShift(TaxiServiceType::Meter);

        $passenger = $this->passenger(900_000);
        $ride = $this->rides->confirm($passenger, $this->token());

        // The fare outgrows the wallet during the ride, which is the risk a
        // meter carries and a flat fare does not.
        app(WalletService::class)->adjust(
            wallet: $this->walletOf($passenger),
            amount: -800_000,
            reason: 'spent elsewhere mid-ride',
            initiatedBy: $passenger,
            idempotencyKey: 'test:drain',
        );

        $ended = $this->rides->end($ride, 'passenger');

        $this->assertSame(TaxiRideStatus::Unpaid, $ended->status);
        $this->assertSame(300_000, $ended->outstanding_amount);
        $this->assertNotNull($ended->ended_at);
    }

    public function test_an_unpaid_ride_blocks_the_next_one(): void
    {
        $this->meterTariff();
        $this->openShift(TaxiServiceType::Meter);

        $passenger = $this->passenger(900_000);
        $ride = $this->rides->confirm($passenger, $this->token());

        app(WalletService::class)->adjust(
            wallet: $this->walletOf($passenger),
            amount: -800_000,
            reason: 'spent elsewhere mid-ride',
            initiatedBy: $passenger,
            idempotencyKey: 'test:drain',
        );

        $this->rides->end($ride, 'passenger');

        $this->assertRefused('outstanding_taxi_fare', fn () => $this->rides->quote($passenger, $this->token()));
    }

    public function test_the_ledger_stays_balanced_across_every_mode(): void
    {
        $this->meterTariff();
        $shift = $this->openShift(TaxiServiceType::Line);

        $passenger = $this->passenger();
        $this->rides->confirm($passenger, $this->token(), 150_000);

        $ledger = app(LedgerService::class);

        $this->assertTrue($ledger->verify($this->walletOf($passenger))['ok']);
        $this->assertTrue($ledger->verify($this->walletOf($this->driver->user))['ok']);
    }

    // ── shift rules ─────────────────────────────────────────────────────────

    public function test_a_taxi_out_of_service_cannot_be_scanned(): void
    {
        $passenger = $this->passenger();

        $this->assertRefused('taxi_not_in_service', fn () => $this->rides->quote($passenger, $this->token()));
    }

    public function test_a_driver_cannot_hold_two_shifts(): void
    {
        $this->openShift(TaxiServiceType::Line);

        $second = Taxi::factory()->inService()->create(['city_id' => $this->city->id]);
        TaxiAssignment::create([
            'taxi_id' => $second->id,
            'driver_id' => $this->driver->id,
            'starts_on' => today(),
            'is_active' => true,
        ]);

        $this->assertRefused(
            'driver_already_on_taxi_shift',
            fn () => $this->shifts->open($this->driver, $second, TaxiServiceType::Charter),
        );
    }

    public function test_an_unassigned_driver_cannot_open_a_shift(): void
    {
        $stranger = Driver::factory()->create(['city_id' => $this->city->id]);

        $this->assertRefused(
            'taxi_not_assigned',
            fn () => $this->shifts->open($stranger, $this->taxi, TaxiServiceType::Charter),
        );
    }

    public function test_a_car_licensed_for_one_mode_cannot_offer_another(): void
    {
        $lineOnly = Taxi::factory()->inService()->onlyMode(TaxiServiceType::Line)->create([
            'city_id' => $this->city->id,
            'default_taxi_line_id' => $this->line->id,
        ]);

        TaxiAssignment::create([
            'taxi_id' => $lineOnly->id,
            'driver_id' => $this->driver->id,
            'starts_on' => today(),
            'is_active' => true,
        ]);

        $this->assertRefused(
            'mode_not_permitted_for_taxi',
            fn () => $this->shifts->open($this->driver, $lineOnly, TaxiServiceType::Meter),
        );
    }

    public function test_the_mode_cannot_change_with_somebody_aboard(): void
    {
        $shift = $this->openShift(TaxiServiceType::Line);
        $passenger = $this->passenger();

        $this->rides->confirm($passenger, $this->token(), 150_000);

        // A passenger who got in for a flat fare must not find themselves on a
        // meter, and the car's colour on the map has to keep meaning what it says.
        $this->assertRefused(
            'cannot_switch_mode_with_passenger',
            fn () => $this->shifts->switchMode($shift->fresh(), TaxiServiceType::Meter),
        );
    }

    public function test_clocking_off_closes_the_rides_still_running(): void
    {
        $this->meterTariff();
        $shift = $this->openShift(TaxiServiceType::Meter);

        $passenger = $this->passenger(5_000_000);
        $ride = $this->rides->confirm($passenger, $this->token());

        $this->shifts->close($shift->fresh());

        $this->assertFalse($ride->fresh()->isOpen());
        $this->assertSame(0, $shift->fresh()->onboard_count);
    }
}
