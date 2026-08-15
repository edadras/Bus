<?php

namespace App\Console\Commands;

use App\Domain\Fleet\Enums\ShiftStatus;
use App\Domain\Fleet\Models\DriverShift;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Services\TripService;
use Illuminate\Console\Command;

/**
 * A driver's phone dies, or the app is killed, and a trip is left "active"
 * forever: it keeps appearing on the live map, blocks the bus from a new
 * shift, and holds passengers in an open ride. This reaps them.
 */
class CloseStaleTripsCommand extends Command
{
    protected $signature = 'transit:trips:close-stale {--minutes=45 : Silence before a trip is considered dead}';

    protected $description = 'Complete trips whose driver has stopped reporting, and close their shifts';

    public function handle(TripService $trips): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $stale = Trip::query()
            ->live()
            ->where(fn ($q) => $q->where('last_ping_at', '<', $cutoff)->orWhereNull('last_ping_at'))
            ->limit(200)
            ->get();

        foreach ($stale as $trip) {
            $trips->complete($trip);
            $this->line("  completed trip {$trip->uuid} (last ping: {$trip->last_ping_at?->diffForHumans()})");
        }

        // A shift with no live trip and no recent activity is also dead.
        $shifts = DriverShift::query()
            ->where('status', ShiftStatus::Open->value)
            ->where('started_at', '<', now()->subHours(16))
            ->limit(100)
            ->get();

        foreach ($shifts as $shift) {
            $trips->endShift($shift);
            $shift->forceFill(['status' => ShiftStatus::ForceClosed])->save();
            $this->line("  force-closed shift #{$shift->id}");
        }

        $this->info("Closed {$stale->count()} trip(s) and {$shifts->count()} shift(s).");

        return self::SUCCESS;
    }
}
