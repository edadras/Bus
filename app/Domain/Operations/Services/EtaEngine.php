<?php

namespace App\Domain\Operations\Services;

use App\Domain\Network\Models\BusStop;
use App\Domain\Network\Models\RouteStop;
use App\Domain\Operations\DTO\EtaEstimate;
use App\Domain\Operations\Enums\TripStatus;
use App\Domain\Operations\Models\SegmentTravelStat;
use App\Domain\Operations\Models\Trip;
use App\Support\Time\TimeBucket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Version 1 arrival prediction — deterministic, explainable, no model to train.
 *
 * Naively dividing straight-line distance by current speed is wrong in the two
 * cases that matter most: a bus stopped at a light reads as "never arriving",
 * and a bus on a clear stretch reads as arriving before it has served three
 * intervening stops. So each remaining segment is priced by blending three
 * independent estimators:
 *
 *   live speed   - responsive, but noisy and useless at a standstill
 *   historical   - the mean travel time for this segment in this (day, hour)
 *                  bucket; only trusted once enough samples exist
 *   baseline     - the route's nominal speed; always available
 *
 * The weights come from config and are renormalised over whichever estimators
 * are actually available, so a brand-new route still predicts sensibly. Dwell
 * time is added per intervening stop, and confidence falls with distance,
 * missing history, and telemetry staleness.
 *
 * Version 2 replaces `segmentSeconds()` with a learned model; the surrounding
 * accumulation, dwell handling and confidence logic stay as they are.
 */
class EtaEngine
{
    public function __construct(private readonly RouteMatcher $matcher) {}

    /** Estimate for one trip reaching one stop. Null when it cannot arrive. */
    public function estimate(Trip $trip, BusStop $stop): ?EtaEstimate
    {
        if (! $trip->status->acceptsTelemetry() || $trip->route === null) {
            return null;
        }

        $stops = $this->matcher->stopsFor($trip->route);

        $target = $stops->first(fn (RouteStop $rs) => $rs->bus_stop_id === $stop->id);

        if ($target === null) {
            return null;
        }

        $currentOffset = (float) $trip->route_offset_meters;

        // Already past this stop on this trip; the passenger wants the next bus.
        if ($target->distance_from_start <= $currentOffset) {
            return null;
        }

        $remaining = $stops->filter(
            fn (RouteStop $rs) => $rs->distance_from_start > $currentOffset
                && $rs->distance_from_start <= $target->distance_from_start
        )->values();

        $totalDistance = $target->distance_from_start - $currentOffset;
        $seconds = 0.0;
        $segmentStart = $currentOffset;
        $previousStopId = $trip->current_stop_id ?? $this->stopBefore($stops, $currentOffset)?->bus_stop_id;
        $historyHits = 0;

        foreach ($remaining as $index => $routeStop) {
            $segmentDistance = max(0.0, $routeStop->distance_from_start - $segmentStart);

            $segment = $this->segmentSeconds(
                trip: $trip,
                fromStopId: $previousStopId,
                toStopId: $routeStop->bus_stop_id,
                distanceMeters: $segmentDistance,
            );

            $seconds += $segment['seconds'];
            $historyHits += $segment['used_history'] ? 1 : 0;

            // Dwell at every stop the bus must serve before the target, but not
            // at the target itself — arrival is what is being predicted.
            if ($index < $remaining->count() - 1) {
                $seconds += $routeStop->dwell_seconds ?: (int) config('transit.eta.dwell_seconds_per_stop');
            }

            $segmentStart = $routeStop->distance_from_start;
            $previousStopId = $routeStop->bus_stop_id;
        }

        $seconds = $this->applyLiveAdjustments($trip, $seconds);

        if ($seconds > (int) config('transit.eta.max_horizon_seconds')) {
            return null;
        }

        return new EtaEstimate(
            seconds: (int) round($seconds),
            confidence: $this->confidence($trip, $remaining->count(), $historyHits, $totalDistance),
            source: $historyHits > 0 ? 'blended' : 'baseline',
            distanceMeters: $totalDistance,
            stopsAway: $remaining->count(),
            components: [
                'segments' => $remaining->count(),
                'history_segments' => $historyHits,
                'live_speed_kmh' => $trip->current_speed_kmh,
                'stale' => $trip->isStale(),
            ],
        );
    }

    /**
     * Predicted travel time for one inter-stop segment.
     *
     * @return array{seconds: float, used_history: bool}
     */
    private function segmentSeconds(Trip $trip, ?int $fromStopId, int $toStopId, float $distanceMeters): array
    {
        if ($distanceMeters <= 0) {
            return ['seconds' => 0.0, 'used_history' => false];
        }

        $weights = (array) config('transit.eta.weights');
        $estimates = [];

        // 1. Live speed. Ignored below a walking pace: a bus waiting at a light
        //    would otherwise project an infinite arrival time.
        $liveSpeed = (float) ($trip->current_speed_kmh ?? 0);
        if ($liveSpeed >= 5 && ! $trip->isStale()) {
            $estimates['live_speed'] = $distanceMeters / ($liveSpeed / 3.6);
        }

        // 2. Historical mean for this segment in this time bucket.
        $usedHistory = false;
        if ($fromStopId !== null) {
            $stat = $this->statFor($trip->route_id, $fromStopId, $toStopId);

            if ($stat !== null && $stat->isTrusted() && $stat->mean_seconds > 0) {
                $estimates['historical'] = $stat->mean_seconds * $stat->traffic_factor;
                $usedHistory = true;
            }
        }

        // 3. Baseline nominal speed; the estimator that is always available.
        $baselineSpeed = (float) config('transit.eta.baseline_speed_kmh');
        $estimates['baseline'] = $distanceMeters / max(1.0, $baselineSpeed / 3.6);

        // Renormalise over the estimators we actually got, so a missing one
        // redistributes its weight rather than silently shrinking the estimate.
        $totalWeight = 0.0;
        $weighted = 0.0;

        foreach ($estimates as $key => $value) {
            $weight = (float) ($weights[$key] ?? 0);

            if ($weight <= 0) {
                continue;
            }

            $weighted += $value * $weight;
            $totalWeight += $weight;
        }

        $seconds = $totalWeight > 0 ? $weighted / $totalWeight : reset($estimates);

        return ['seconds' => max(0.0, $seconds), 'used_history' => $usedHistory];
    }

    /** Penalties for observed conditions the segment model cannot see. */
    private function applyLiveAdjustments(Trip $trip, float $seconds): float
    {
        // A bus known to be standing still will not start moving instantly.
        if ($trip->is_idle) {
            $seconds += 45;
        }

        // Off-route means the matched offset is suspect; widen the estimate
        // rather than reporting a precise-looking number that is likely wrong.
        if ($trip->is_off_route) {
            $seconds *= 1.25;
        }

        return $seconds;
    }

    /**
     * Confidence decays with prediction horizon and rises with real history.
     * It is a calibration hint for the UI, not a probability.
     */
    private function confidence(Trip $trip, int $stopsAway, int $historyHits, float $distanceMeters): float
    {
        $confidence = 0.95;

        // Each additional stop compounds dwell and traffic uncertainty.
        $confidence -= min(0.35, $stopsAway * 0.05);

        // Long horizons are inherently less certain.
        $confidence -= min(0.2, $distanceMeters / 20000);

        // Segments without trusted history lean on the baseline alone.
        if ($stopsAway > 0) {
            $confidence -= 0.15 * (1 - $historyHits / $stopsAway);
        }

        if ($trip->isStale()) {
            $confidence -= 0.3;
        }

        if ($trip->is_off_route) {
            $confidence -= 0.2;
        }

        return round(max(0.05, min(1.0, $confidence)), 3);
    }

    /**
     * The arrival board for one stop: every live trip that still has to reach
     * it, soonest first. Cached briefly — a board is read far more often than
     * the underlying positions change.
     *
     * @return array<int, array<string, mixed>>
     */
    public function arrivalsForStop(BusStop $stop, ?int $lineId = null, int $limit = 10): array
    {
        $cacheKey = "eta:stop:{$stop->id}:".($lineId ?? 'all').":$limit";

        return Cache::remember($cacheKey, (int) config('transit.eta.cache_ttl'), function () use ($stop, $lineId, $limit) {
            $routeIds = RouteStop::where('bus_stop_id', $stop->id)->pluck('route_id')->unique();

            if ($routeIds->isEmpty()) {
                return [];
            }

            $trips = Trip::query()
                ->with(['bus:id,uuid,bus_number,capacity_seated,capacity_standing', 'line', 'route', 'destinationStop:id,name'])
                ->whereIn('route_id', $routeIds)
                ->whereIn('status', [TripStatus::Active->value, TripStatus::Starting->value])
                ->when($lineId !== null, fn ($q) => $q->where('bus_line_id', $lineId))
                ->where('last_ping_at', '>=', now()->subMinutes(10))
                ->get();

            return $trips
                ->map(function (Trip $trip) use ($stop) {
                    $eta = $this->estimate($trip, $stop);

                    return $eta === null ? null : [
                        'trip_uuid' => $trip->uuid,
                        'bus_number' => $trip->bus?->bus_number,
                        'line_code' => $trip->line?->code,
                        'line_name' => $trip->line?->name,
                        'line_color' => $trip->line?->color,
                        'destination' => $trip->destinationStop?->name ?? $trip->line?->destination_label,
                        'passenger_count' => $trip->passenger_count,
                        'occupancy' => $trip->occupancyRatio(),
                        'eta' => $eta->toArray(),
                    ];
                })
                ->filter()
                ->sortBy('eta.seconds')
                ->take($limit)
                ->values()
                ->all();
        });
    }

    public function flushStopCache(BusStop $stop): void
    {
        Cache::forget("eta:stop:{$stop->id}:all:10");
    }

    /**
     * Fold an observed segment travel time into the rolling statistics. Called
     * from the trip pipeline whenever a bus completes a stop-to-stop hop.
     */
    public function recordObservation(int $routeId, int $fromStopId, int $toStopId, float $seconds): void
    {
        // Guard against nonsense observations poisoning the mean: a hop that
        // took under 5s or over an hour is a data problem, not traffic.
        if ($seconds < 5 || $seconds > 3600) {
            return;
        }

        $bucket = TimeBucket::of(now());

        $stat = SegmentTravelStat::firstOrNew([
            'route_id' => $routeId,
            'from_stop_id' => $fromStopId,
            'to_stop_id' => $toStopId,
            'day_type' => $bucket['day_type'],
            'hour_bucket' => $bucket['hour_bucket'],
        ]);

        $stat->addSample($seconds);
        $stat->save();

        Cache::forget($this->statCacheKey($routeId, $fromStopId, $toStopId));
    }

    private function statFor(int $routeId, int $fromStopId, int $toStopId): ?SegmentTravelStat
    {
        return Cache::remember(
            $this->statCacheKey($routeId, $fromStopId, $toStopId),
            120,
            function () use ($routeId, $fromStopId, $toStopId) {
                $bucket = TimeBucket::of(now());

                return SegmentTravelStat::where('route_id', $routeId)
                    ->where('from_stop_id', $fromStopId)
                    ->where('to_stop_id', $toStopId)
                    ->where('day_type', $bucket['day_type'])
                    ->where('hour_bucket', $bucket['hour_bucket'])
                    ->first();
            },
        );
    }

    private function statCacheKey(int $routeId, int $fromStopId, int $toStopId): string
    {
        return "eta:stat:$routeId:$fromStopId:$toStopId:".TimeBucket::key(now());
    }

    /** @param Collection<int, RouteStop> $stops */
    private function stopBefore(Collection $stops, float $offset): ?RouteStop
    {
        return $stops->last(fn (RouteStop $rs) => $rs->distance_from_start <= $offset);
    }
}
