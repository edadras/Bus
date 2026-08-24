<?php

namespace App\Console\Commands;

use App\Domain\SchoolTransport\Models\SchoolServiceRoute;
use App\Domain\SchoolTransport\Services\SchoolTripService;
use Illuminate\Console\Command;

/**
 * Build the runs the school routes owe.
 *
 * Runs are created a couple of days ahead so a driver opening the app before
 * dawn already has the morning's manifest, and so a family reporting an
 * absence the night before has a run to report it against. Creation is
 * idempotent per (route, date, direction), which is what makes running this on
 * a schedule and clicking it by hand in the panel safe on the same day.
 */
class ScheduleSchoolRunsCommand extends Command
{
    protected $signature = 'school:runs:schedule {--days= : How many days ahead to build}';

    protected $description = 'Create the school service runs for today and the next few days';

    public function handle(SchoolTripService $trips): int
    {
        $days = (int) ($this->option('days') ?? config('school.trips.schedule_days_ahead', 2));

        $created = 0;
        $dates = [];

        for ($offset = 0; $offset <= max(0, $days); $offset++) {
            $dates[] = today()->addDays($offset);
        }

        SchoolServiceRoute::query()
            ->active()
            // `readinessBlocker()` reads through to both the van and the
            // driver, so both are loaded here. Without them this job — the one
            // that builds tomorrow morning before anybody is awake — throws.
            ->with(['contracts.student', 'school', 'vehicle', 'driver'])
            ->chunkById(100, function ($routes) use ($trips, $dates, &$created): void {
                foreach ($routes as $route) {
                    // A route with no van or no driver cannot run; scheduling
                    // one would put an empty manifest in front of nobody.
                    if ($route->readinessBlocker() !== null) {
                        continue;
                    }

                    foreach ($dates as $date) {
                        foreach ($trips->scheduleFor($route, $date) as $trip) {
                            if ($trip->wasRecentlyCreated) {
                                $created++;
                            }
                        }
                    }
                }
            });

        $this->info("Scheduled $created school run(s).");

        return self::SUCCESS;
    }
}
