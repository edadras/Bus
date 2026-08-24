<?php

namespace App\Domain\Taxi\Services;

use App\Domain\Fleet\Enums\ShiftStatus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Enums\TaxiStatus;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiLine;
use App\Domain\Taxi\Models\TaxiShift;
use App\Support\Exceptions\DomainException;
use App\Support\Geo\Coordinate;
use Illuminate\Support\Facades\DB;

/**
 * Clocking a taxi on and off, and changing what it is offering.
 *
 * The shift is where the mode lives, which makes it the thing that decides how
 * the next scan is priced and what colour the car is on the map. Everything
 * that could make that ambiguous is refused here rather than handled later:
 * one open shift per driver, one per car, and no changing mode with somebody
 * still aboard.
 */
class TaxiShiftService
{
    public function __construct(
        private readonly TaxiLiveService $live,
        private readonly TaxiRideService $rides,
    ) {}

    public function open(
        Driver $driver,
        Taxi $taxi,
        TaxiServiceType $mode,
        ?TaxiLine $line = null,
        ?Coordinate $at = null,
    ): TaxiShift {
        $blocker = $driver->taxiOperationBlocker($taxi);

        if ($blocker !== null) {
            throw DomainException::make($blocker, 403, ['taxi_number' => $taxi->taxi_number]);
        }

        if (! $taxi->allowsMode($mode)) {
            throw DomainException::make('mode_not_permitted_for_taxi', 422, [
                'mode' => $mode->value,
                'allowed' => array_map(fn (TaxiServiceType $m) => $m->value, $taxi->allowedModes()),
            ]);
        }

        $line = $this->resolveLine($taxi, $mode, $line);

        return DB::transaction(function () use ($driver, $taxi, $mode, $line, $at): TaxiShift {
            // Locked so two taps on the button cannot open two shifts, which
            // would leave two cars' worth of takings on one car.
            $locked = Taxi::whereKey($taxi->id)->lockForUpdate()->firstOrFail();

            if ($locked->current_shift_id !== null) {
                throw DomainException::make('taxi_already_in_service', 409, [
                    'taxi_number' => $locked->taxi_number,
                ]);
            }

            $existing = TaxiShift::where('driver_id', $driver->id)->open()->first();

            if ($existing !== null) {
                throw DomainException::make('driver_already_on_taxi_shift', 409, [
                    'shift_uuid' => $existing->uuid,
                ]);
            }

            $shift = TaxiShift::create([
                'taxi_id' => $locked->id,
                'driver_id' => $driver->id,
                'city_id' => $locked->city_id,
                'service_type' => $mode,
                'taxi_line_id' => $line?->id,
                'started_at' => now(),
                'status' => ShiftStatus::Open,
                'start_lat' => $at?->lat,
                'start_lng' => $at?->lng,
            ]);

            $locked->forceFill([
                'current_shift_id' => $shift->id,
                'current_driver_id' => $driver->id,
                'status' => TaxiStatus::Active,
            ])->save();

            return $shift;
        });
    }

    /**
     * Change what the car is offering.
     *
     * Refused while anybody is aboard: a passenger who got in for a flat-fare
     * line ride must not find themselves on a meter, and the car's colour on
     * the map has to keep meaning what it says.
     */
    public function switchMode(TaxiShift $shift, TaxiServiceType $mode, ?TaxiLine $line = null): TaxiShift
    {
        $this->assertOpen($shift);

        $taxi = $shift->taxi;

        if (! $taxi->allowsMode($mode)) {
            throw DomainException::make('mode_not_permitted_for_taxi', 422, ['mode' => $mode->value]);
        }

        if ($shift->rides()->open()->exists()) {
            throw DomainException::make('cannot_switch_mode_with_passenger', 409);
        }

        $line = $this->resolveLine($taxi, $mode, $line);

        $shift->forceFill([
            'service_type' => $mode,
            'taxi_line_id' => $line?->id,
            // A price named under the old mode means nothing under the new one.
            'pending_charter_amount' => null,
            'pending_charter_set_at' => null,
        ])->save();

        $this->live->publish($shift->fresh());

        return $shift;
    }

    /** The driver names a price for the hire standing in front of them. */
    public function setCharterAmount(TaxiShift $shift, int $amount): TaxiShift
    {
        $this->assertOpen($shift);

        if ($shift->service_type !== TaxiServiceType::Charter) {
            throw DomainException::make('not_a_charter_shift', 422, [
                'mode' => $shift->service_type->value,
            ]);
        }

        $max = (int) config('taxi.charter.max_amount');

        if ($amount <= 0 || $amount > $max) {
            throw DomainException::make('invalid_charter_amount', 422, [
                'amount' => $amount,
                'maximum' => $max,
            ]);
        }

        $shift->forceFill([
            'pending_charter_amount' => $amount,
            'pending_charter_set_at' => now(),
        ])->save();

        return $shift;
    }

    public function clearCharterAmount(TaxiShift $shift): TaxiShift
    {
        $shift->forceFill([
            'pending_charter_amount' => null,
            'pending_charter_set_at' => null,
        ])->save();

        return $shift;
    }

    /**
     * Clock off.
     *
     * Open rides are closed first and on purpose: a metered ride left running
     * when the driver goes home would keep billing, and a line passenger left
     * aboard would keep a seat occupied on a car that is no longer working.
     */
    public function close(TaxiShift $shift, ?Coordinate $at = null, string $reason = 'driver'): TaxiShift
    {
        if (! $shift->isOpen()) {
            return $shift;
        }

        foreach ($shift->rides()->open()->get() as $ride) {
            $this->rides->end($ride, endedBy: $reason === 'driver' ? 'driver' : 'system', at: $at);
        }

        return DB::transaction(function () use ($shift, $at, $reason): TaxiShift {
            $shift->refresh();

            $shift->forceFill([
                'status' => $reason === 'driver' ? ShiftStatus::Closed : ShiftStatus::ForceClosed,
                'ended_at' => now(),
                'end_lat' => $at?->lat,
                'end_lng' => $at?->lng,
                'onboard_count' => 0,
            ])->save();

            Taxi::whereKey($shift->taxi_id)->update([
                'current_shift_id' => null,
                'status' => TaxiStatus::Idle,
            ]);

            $this->live->forget($shift);

            return $shift;
        });
    }

    /** The shift a driver is currently working, if any. */
    public function openShiftFor(Driver $driver): ?TaxiShift
    {
        return TaxiShift::with(['taxi', 'line'])
            ->where('driver_id', $driver->id)
            ->open()
            ->latest('started_at')
            ->first();
    }

    private function assertOpen(TaxiShift $shift): void
    {
        if (! $shift->isOpen()) {
            throw DomainException::make('shift_not_open', 409, ['shift_uuid' => $shift->uuid]);
        }
    }

    /**
     * A line shift must have a line: without one there is no published fare,
     * and a shared taxi with no fare is not a product, it is a bug waiting to
     * charge somebody nothing.
     */
    private function resolveLine(Taxi $taxi, TaxiServiceType $mode, ?TaxiLine $line): ?TaxiLine
    {
        if ($mode !== TaxiServiceType::Line) {
            return null;
        }

        $line ??= $taxi->defaultLine;

        if ($line === null) {
            throw DomainException::make('line_required_for_line_service', 422);
        }

        if ($line->city_id !== $taxi->city_id) {
            throw DomainException::make('line_city_mismatch', 422);
        }

        if (! $line->is_active) {
            throw DomainException::make('taxi_line_inactive', 422, ['line' => $line->code]);
        }

        return $line;
    }
}
