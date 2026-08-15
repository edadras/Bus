<?php

namespace App\Console\Commands;

use App\Domain\Analytics\Services\DashboardService;
use App\Domain\Network\Models\City;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RollupMetricsCommand extends Command
{
    protected $signature = 'transit:metrics:rollup {--date= : Y-m-d, defaults to yesterday} {--days=1 : Backfill this many days}';

    protected $description = 'Aggregate daily transport, ridership and financial metrics for the dashboard';

    public function handle(DashboardService $dashboard): int
    {
        $end = $this->option('date') ? Carbon::parse($this->option('date')) : now()->subDay();
        $days = max(1, (int) $this->option('days'));

        foreach (City::active()->get() as $city) {
            for ($offset = 0; $offset < $days; $offset++) {
                $date = $end->copy()->subDays($offset);
                $metric = $dashboard->rollup($city, $date);

                $this->line(sprintf(
                    '  %s %s: %d trips, %d boardings, revenue %d',
                    $city->slug, $date->toDateString(),
                    $metric->trips_count, $metric->boardings_count, $metric->fare_revenue,
                ));
            }
        }

        return self::SUCCESS;
    }
}
