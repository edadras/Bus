<?php

namespace App\Console\Commands;

use App\Domain\SchoolTransport\Services\SchoolTripService;
use Illuminate\Console\Command;

/**
 * Close runs a driver left open.
 *
 * Closing matters more here than for a bus: while a run is open the van's
 * position is visible to the families on it, so a run nobody closed is a
 * position nobody meant to keep sharing.
 */
class CloseStaleSchoolRunsCommand extends Command
{
    protected $signature = 'school:runs:close-stale';

    protected $description = 'Force-close school runs left open long after they started';

    public function handle(SchoolTripService $trips): int
    {
        $closed = $trips->closeAbandoned();

        $this->info("Closed $closed stale school run(s).");

        return self::SUCCESS;
    }
}
