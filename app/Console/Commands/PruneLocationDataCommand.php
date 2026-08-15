<?php

namespace App\Console\Commands;

use App\Domain\Operations\Models\TripLocation;
use App\Domain\Ridership\Models\PassengerLocationPing;
use Illuminate\Console\Command;

/**
 * Location history is the most sensitive data the platform holds and the
 * fastest growing table by a wide margin. Passenger pings are kept only as
 * long as they are needed to close a ride; bus traces are kept for the
 * configured operational window and then dropped.
 */
class PruneLocationDataCommand extends Command
{
    protected $signature = 'transit:prune:locations {--chunk=5000}';

    protected $description = 'Delete GPS history past its retention window';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');

        // Passenger positions: 24 hours is far beyond what alighting detection
        // needs, and keeping more would be an unjustified privacy cost.
        $passengerCutoff = now()->subDay();
        $passengerDeleted = 0;

        do {
            $deleted = PassengerLocationPing::where('recorded_at', '<', $passengerCutoff)->limit($chunk)->delete();
            $passengerDeleted += $deleted;
        } while ($deleted === $chunk);

        $busCutoff = now()->subDays((int) config('transit.gps.retention_days'));
        $busDeleted = 0;

        do {
            $deleted = TripLocation::where('recorded_at', '<', $busCutoff)->limit($chunk)->delete();
            $busDeleted += $deleted;
        } while ($deleted === $chunk);

        $this->info("Pruned $passengerDeleted passenger ping(s) and $busDeleted bus location(s).");

        return self::SUCCESS;
    }
}
