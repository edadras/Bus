<?php

namespace App\Console\Commands;

use App\Domain\Network\Models\BusRoute;
use App\Domain\Operations\Services\RouteMatcher;
use Illuminate\Console\Command;

class RecalculateRoutesCommand extends Command
{
    protected $signature = 'transit:routes:recalculate {--city= : Limit to one city slug} {--route= : A single route id}';

    protected $description = 'Re-snap every route stop onto its route geometry and refresh route distances';

    public function handle(RouteMatcher $matcher): int
    {
        $routes = BusRoute::query()
            ->when($this->option('route'), fn ($q) => $q->whereKey($this->option('route')))
            ->when($this->option('city'), fn ($q) => $q->whereHas(
                'line.city',
                fn ($c) => $c->where('slug', $this->option('city')),
            ))
            ->with('line')
            ->get();

        $bar = $this->output->createProgressBar($routes->count());

        foreach ($routes as $route) {
            $updated = $matcher->recalculateStopOffsets($route);
            $bar->advance();

            $this->line(" {$route->line?->code} / {$route->direction->value}: $updated stops, {$route->fresh()->distance_meters}m");
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Recalculated {$routes->count()} route(s).");

        return self::SUCCESS;
    }
}
