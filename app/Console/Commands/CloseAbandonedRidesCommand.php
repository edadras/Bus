<?php

namespace App\Console\Commands;

use App\Domain\Ridership\Services\AlightingService;
use Illuminate\Console\Command;

class CloseAbandonedRidesCommand extends Command
{
    protected $signature = 'transit:rides:close-abandoned';

    protected $description = 'Force-close passenger rides left open long after their bus trip ended';

    public function handle(AlightingService $alighting): int
    {
        $closed = $alighting->closeAbandoned();

        $this->info("Closed $closed abandoned ride(s).");

        return self::SUCCESS;
    }
}
