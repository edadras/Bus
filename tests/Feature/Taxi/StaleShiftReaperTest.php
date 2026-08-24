<?php

namespace Tests\Feature\Taxi;

use App\Domain\Fleet\Enums\ShiftStatus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Network\Models\City;
use App\Domain\Taxi\Enums\TaxiRideStatus;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Enums\TaxiStatus;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiAssignment;
use App\Domain\Taxi\Models\TaxiLine;
use App\Domain\Taxi\Models\TaxiQrCode;
use App\Domain\Taxi\Models\TaxiShift;
use App\Domain\Taxi\Models\TaxiTariff;
use App\Domain\Taxi\Services\TaxiQrService;
use App\Domain\Taxi\Services\TaxiRideService;
use App\Domain\Taxi\Services\TaxiShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * What happens when nobody presses stop.
 *
 * Both faults here cost somebody money if they are left: a meter still running
 * after the car has been parked all night keeps billing a passenger, and a
 * shift nobody closed keeps a car on the passenger's map that is not working
 * and keeps its fare code live.
 */
class StaleShiftReaperTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Taxi $taxi;

    private Driver $driver;

    private TaxiQrCode $qr;

    private TaxiLine $line;

    private TaxiShiftService $shifts;

    private TaxiRideService $rides;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shifts = app(TaxiShiftService::class);
        $this->rides = app(TaxiRideService::class);

        $this->city = $this->makeCity();
        $this->line = TaxiLine::factory()->create([
            'city_id' => $this->city->id,
            'flat_fare' => 150_000,
        ]);

        $this->driver = Driver::factory()->create(['city_id' => $this->city->id]);
        $this->taxi = Taxi::factory()->inService()->create([
            'city_id' => $this->city->id,
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
        return $this->shifts->open(
            $this->driver,
            $this->taxi->fresh(),
            $mode,
            $mode === TaxiServiceType::Line ? $this->line : null,
        );
    }

    // ── runaway meters ──────────────────────────────────────────────────────

    public function test_a_meter_past_its_ceiling_is_ended_by_the_system(): void
    {
        TaxiTariff::factory()->create([
            'city_id' => $this->city->id,
            'service_type' => TaxiServiceType::Meter,
        ]);

        $this->openShift(TaxiServiceType::Meter);

        $passenger = $this->makePassenger($this->city, 5_000_000);
        RateLimiter::clear('taxi-ride:'.$passenger->id);

        $ride = $this->rides->confirm($passenger, $this->token());

        $ride->forceFill([
            'started_at' => now()->subMinutes((int) config('taxi.meter.max_duration_minutes') + 30),
        ])->save();

        $this->artisan('taxi:shifts:close-stale')->assertSuccessful();

        $closed = $ride->fresh();

        $this->assertNotSame(TaxiRideStatus::Active, $closed->status);
        $this->assertSame('system', $closed->ended_by);
    }

    public function test_a_meter_running_ten_minutes_is_left_alone(): void
    {
        TaxiTariff::factory()->create([
            'city_id' => $this->city->id,
            'service_type' => TaxiServiceType::Meter,
        ]);

        $this->openShift(TaxiServiceType::Meter);

        $passenger = $this->makePassenger($this->city, 5_000_000);
        RateLimiter::clear('taxi-ride:'.$passenger->id);

        $ride = $this->rides->confirm($passenger, $this->token());
        $ride->forceFill(['started_at' => now()->subMinutes(10)])->save();

        $this->artisan('taxi:shifts:close-stale')->assertSuccessful();

        $this->assertSame(TaxiRideStatus::Active, $ride->fresh()->status);
    }

    // ── abandoned shifts ────────────────────────────────────────────────────

    public function test_a_shift_left_open_overnight_is_force_closed(): void
    {
        $shift = $this->openShift(TaxiServiceType::Line);

        $shift->forceFill([
            'started_at' => now()->subHours((int) config('taxi.shifts.auto_close_after_hours') + 2),
        ])->save();

        $this->artisan('taxi:shifts:close-stale')->assertSuccessful();

        $closed = $shift->fresh();

        // Force-closed, not closed: the record says the driver did not do it,
        // which is the difference an operator asking why needs.
        $this->assertSame(ShiftStatus::ForceClosed, $closed->status);
        $this->assertNotNull($closed->ended_at);
    }

    public function test_force_closing_a_shift_frees_the_car(): void
    {
        $shift = $this->openShift(TaxiServiceType::Line);

        $shift->forceFill([
            'started_at' => now()->subHours((int) config('taxi.shifts.auto_close_after_hours') + 2),
        ])->save();

        $this->artisan('taxi:shifts:close-stale')->assertSuccessful();

        $taxi = $this->taxi->fresh();

        $this->assertNull($taxi->current_shift_id);
        $this->assertSame(TaxiStatus::Idle, $taxi->status);
    }

    public function test_a_shift_that_started_this_morning_keeps_working(): void
    {
        $shift = $this->openShift(TaxiServiceType::Line);
        $shift->forceFill(['started_at' => now()->subHours(3)])->save();

        $this->artisan('taxi:shifts:close-stale')->assertSuccessful();

        $this->assertSame(ShiftStatus::Open, $shift->fresh()->status);
    }

    public function test_closing_an_abandoned_shift_does_not_strand_its_passengers(): void
    {
        $shift = $this->openShift(TaxiServiceType::Line);

        $passenger = $this->makePassenger($this->city, 1_000_000);
        RateLimiter::clear('taxi-ride:'.$passenger->id);

        $ride = $this->rides->confirm($passenger, $this->token(), 150_000);

        $shift->forceFill([
            'started_at' => now()->subHours((int) config('taxi.shifts.auto_close_after_hours') + 2),
        ])->save();

        $this->artisan('taxi:shifts:close-stale')->assertSuccessful();

        // A line passenger left aboard would hold a seat on a car that has
        // stopped working, and the fare is already paid either way.
        $this->assertSame(TaxiRideStatus::Completed, $ride->fresh()->status);
    }
}
