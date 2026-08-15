<?php

namespace App\Domain\Operations\Services;

use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\RouteStop;
use App\Domain\Operations\DTO\RouteMatch;
use App\Support\Geo\Coordinate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Projects a GPS point onto a route and works out where the bus is in the
 * sequence of stops.
 *
 * Everything is expressed as a single scalar — metres travelled along the
 * polyline — because that makes the questions the product asks ("which stop is
 * next", "how far to it", "did we just pass one") into simple comparisons
 * instead of repeated geometry. Route geometry and stop offsets are cached, so
 * the per-ping cost is one polyline scan.
 */
class RouteMatcher
{
    private const GEOMETRY_CACHE_TTL = 3600;

    public function match(BusRoute $route, Coordinate $point, ?float $previousOffset = null): RouteMatch
    {
        $polyline = $this->polylineFor($route);
        $stops = $this->stopsFor($route);

        if ($polyline->isEmpty()) {
            return new RouteMatch(0.0, 0.0, false, $stops->first(), null, null);
        }

        $snap = $polyline->snap($point);
        $offset = $snap['offset'];
        $deviation = $snap['distance'];

        $threshold = (float) config('transit.gps.off_route_threshold_meters');

        // A route may double back on itself; when it does, the nearest point on
        // the polyline can jump backwards. Anchoring to the previous offset
        // keeps progress monotonic for all but genuinely large corrections.
        if ($previousOffset !== null && $offset < $previousOffset - 200 && $deviation < $threshold) {
            $offset = $previousOffset;
        }

        $previousStop = $stops->last(fn (RouteStop $stop) => $stop->distance_from_start <= $offset + 1);
        $nextStop = $stops->first(fn (RouteStop $stop) => $stop->distance_from_start > $offset);

        $passed = $previousOffset === null
            ? []
            : $stops->filter(fn (RouteStop $stop) => $stop->distance_from_start > $previousOffset
                && $stop->distance_from_start <= $offset)->values()->all();

        return new RouteMatch(
            offsetMeters: $offset,
            deviationMeters: $deviation,
            isOffRoute: $deviation > $threshold,
            nextStop: $nextStop,
            previousStop: $previousStop,
            distanceToNextStop: $nextStop === null ? null : max(0.0, $nextStop->distance_from_start - $offset),
            passedStops: $passed,
        );
    }

    /** The stop the bus is currently standing at, if it is inside a geofence. */
    public function stopAt(BusRoute $route, Coordinate $point): ?RouteStop
    {
        return $this->stopsFor($route)
            ->first(function (RouteStop $routeStop) use ($point): bool {
                $stop = $routeStop->stop;

                return $stop !== null && $stop->distanceTo($point) <= $stop->geofence_radius;
            });
    }

    /** @return Collection<int, RouteStop> ordered by sequence */
    public function stopsFor(BusRoute $route): Collection
    {
        return Cache::remember(
            "route:{$route->id}:stops:v{$route->updated_at?->timestamp}",
            self::GEOMETRY_CACHE_TTL,
            fn () => $route->routeStops()->with('stop')->get(),
        );
    }

    public function polylineFor(BusRoute $route): \App\Support\Geo\Polyline
    {
        return $route->polyline();
    }

    /**
     * Recompute every stop's distance_from_start by snapping it to the route
     * geometry. Run after importing or editing a route; without it, ETA and
     * "next stop" are both meaningless.
     */
    public function recalculateStopOffsets(BusRoute $route): int
    {
        $polyline = $route->polyline();

        if ($polyline->isEmpty()) {
            return 0;
        }

        $updated = 0;
        $previousOffset = 0.0;

        foreach ($route->routeStops()->with('stop')->get() as $routeStop) {
            if ($routeStop->stop === null) {
                continue;
            }

            $snap = $polyline->snap($routeStop->stop->coordinate());

            // Offsets must increase along the sequence; a stop that snaps
            // behind its predecessor means bad geometry, so clamp instead of
            // writing a value that would make distances negative.
            $offset = max($snap['offset'], $previousOffset);

            $routeStop->forceFill(['distance_from_start' => (int) round($offset)])->save();

            $previousOffset = $offset;
            $updated++;
        }

        $route->forceFill(['distance_meters' => (int) round($polyline->length())])->save();

        Cache::forget("route:{$route->id}:stops:v{$route->updated_at?->timestamp}");

        return $updated;
    }
}
