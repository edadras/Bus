<?php

namespace App\Console\Commands;

use App\Domain\Taxi\Models\TaxiRide;
use App\Domain\Taxi\Models\TaxiShift;
use App\Domain\Taxi\Services\TaxiMeterService;
use App\Domain\Taxi\Services\TaxiRideService;
use App\Domain\Taxi\Services\TaxiShiftService;
use Illuminate\Console\Command;

/**
 * Reap taxi shifts and rides nobody closed.
 *
 * Two separate faults, both of which cost somebody money if left:
 *
 *  - A meter still running hours after the car stopped. That is a fault, not a
 *    fare, so the ride is ended at the ceiling rather than left to bill a
 *    passenger for a night's parking.
 *
 *  - A shift left open when the driver went home. It keeps a car on the
 *    passenger map that is not working, and keeps its fare code live.
 *
 * Rides are ended before shifts because closing a shift ends its rides anyway;
 * doing the overrun pass first means each one is closed for the right reason.
 */
class CloseStaleTaxiShiftsCommand extends Command
{
    protected $signature = 'taxi:shifts:close-stale';

    protected $description = 'End runaway taxi meters and force-close shifts left open';

    public function handle(
        TaxiShiftService $shifts,
        TaxiRideService $rides,
        TaxiMeterService $meter,
    ): int {
        $endedRides = 0;

        TaxiRide::query()
            ->open()
            ->chunkById(200, function ($open) use ($rides, $meter, &$endedRides): void {
                foreach ($open as $ride) {
                    if (! $meter->hasOverrun($ride)) {
                        continue;
                    }

                    $rides->end($ride, endedBy: 'system');
                    $endedRides++;
                }
            });

        $cutoff = now()->subHours((int) config('taxi.shifts.auto_close_after_hours', 16));
        $closedShifts = 0;

        TaxiShift::query()
            ->open()
            ->where('started_at', '<', $cutoff)
            ->with('taxi')
            ->chunkById(100, function ($stale) use ($shifts, &$closedShifts): void {
                foreach ($stale as $shift) {
                    $shifts->close($shift, reason: 'system');
                    $closedShifts++;
                }
            });

        $this->info("Ended $endedRides runaway ride(s); force-closed $closedShifts shift(s).");

        return self::SUCCESS;
    }
}
