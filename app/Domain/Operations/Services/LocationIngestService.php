<?php

namespace App\Domain\Operations\Services;

use App\Domain\Network\Models\RouteStop;
use App\Domain\Operations\DTO\LocationPing;
use App\Domain\Operations\Enums\TripEventType;
use App\Domain\Operations\Events\BusLocationUpdated;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripLocation;
use App\Support\Exceptions\DomainException;
use App\Support\Geo\Distance;
use Illuminate\Support\Facades\Cache;

/**
 * The hot path: every driver app reports here, several times a minute, per bus.
 *
 * Responsibilities, in order:
 *   1. reject implausible or duplicated pings before they touch the database
 *   2. match the ping to the route and derive progress, next stop, deviation
 *   3. raise stop arrival / departure / off-route / idle events exactly once
 *   4. feed the ETA engine an observed segment time when a hop completes
 *   5. publish the new position to the live store and the broadcast channel
 *
 * Everything expensive is derived once here rather than per map viewer.
 */
class LocationIngestService
{
    public function __construct(
        private readonly RouteMatcher $matcher,
        private readonly LiveStateStore $liveState,
        private readonly EtaEngine $eta,
        private readonly TripEventRecorder $events,
    ) {}

    /** @return array<string, mixed> the driver app's next instructions */
    public function ingest(Trip $trip, LocationPing $ping): array
    {
        if (! $trip->status->acceptsTelemetry()) {
            throw DomainException::make('trip_not_accepting_telemetry', 422, [
                'status' => $trip->status->value,
            ]);
        }

        $this->assertPlausible($trip, $ping);

        if ($this->isThrottled($trip, $ping)) {
            // Not an error: the app may batch or retry. Acknowledge and tell it
            // the cadence we actually want rather than burning a write.
            return $this->acknowledgement($trip, throttled: true);
        }

        $previousOffset = $trip->route_offset_meters > 0 ? (float) $trip->route_offset_meters : null;
        $previousStopId = $trip->current_stop_id;

        $match = $this->matcher->match($trip->route, $ping->coordinate, $previousOffset);
        $atStop = $this->matcher->stopAt($trip->route, $ping->coordinate);

        $location = TripLocation::create([
            'trip_id' => $trip->id,
            'bus_id' => $trip->bus_id,
            'driver_id' => $trip->driver_id,
            'lat' => $ping->coordinate->lat,
            'lng' => $ping->coordinate->lng,
            'speed_kmh' => $ping->speedKmh,
            'heading' => $ping->heading,
            'accuracy_meters' => $ping->accuracyMeters,
            'altitude' => $ping->altitude,
            'route_offset_meters' => (int) round($match->offsetMeters),
            'route_deviation_meters' => round($match->deviationMeters, 2),
            'nearest_stop_id' => $match->nextStop?->bus_stop_id,
            'recorded_at' => $ping->recordedAt,
            'received_at' => now(),
        ]);

        $travelled = $previousOffset === null ? 0 : max(0, $match->offsetMeters - $previousOffset);

        $trip->forceFill([
            'current_lat' => $ping->coordinate->lat,
            'current_lng' => $ping->coordinate->lng,
            'current_speed_kmh' => $ping->speedKmh,
            'current_heading' => $ping->heading,
            'route_offset_meters' => (int) round($match->offsetMeters),
            'current_stop_id' => $atStop?->bus_stop_id ?? $match->previousStop?->bus_stop_id,
            'next_stop_id' => $match->nextStop?->bus_stop_id,
            'next_stop_sequence' => $match->nextStop?->sequence,
            'distance_to_next_stop' => $match->distanceToNextStop === null
                ? null
                : (int) round($match->distanceToNextStop),
            'distance_meters' => $trip->distance_meters + (int) round($travelled),
            'last_ping_at' => now(),
        ])->save();

        $this->handleStopTransitions($trip, $match, $atStop, $previousStopId);
        $this->handleOffRoute($trip, $match);
        $this->handleIdle($trip, $ping);
        $this->refreshNextStopEta($trip);

        $state = $this->buildLiveState($trip, $ping, $match);
        $this->liveState->put($trip, $state);

        BusLocationUpdated::dispatch($trip->id, $trip->city_id, $state);

        return $this->acknowledgement($trip);
    }

    /**
     * Reject pings that cannot be true. Filtering here keeps a faulty or
     * spoofed device from corrupting distance totals and historical stats.
     */
    private function assertPlausible(Trip $trip, LocationPing $ping): void
    {
        $maxAccuracy = (float) config('transit.gps.max_accuracy_meters');

        if ($ping->accuracyMeters !== null && $ping->accuracyMeters > $maxAccuracy) {
            throw DomainException::make('gps_accuracy_too_low', 422, [
                'accuracy' => $ping->accuracyMeters,
                'maximum' => $maxAccuracy,
            ]);
        }

        $maxSpeed = (float) config('transit.gps.max_plausible_speed_kmh');

        if ($ping->speedKmh !== null && $ping->speedKmh > $maxSpeed) {
            throw DomainException::make('gps_speed_implausible', 422, ['speed' => $ping->speedKmh]);
        }

        // A timestamp from the future means a broken device clock; a very old
        // one means a stale buffered ping. Neither should move the bus.
        if ($ping->recordedAt->isAfter(now()->addMinutes(2))) {
            throw DomainException::make('gps_timestamp_in_future', 422);
        }

        if ($ping->recordedAt->isBefore(now()->subHour())) {
            throw DomainException::make('gps_timestamp_too_old', 422);
        }

        // Teleport check: the implied speed between consecutive fixes.
        $last = $trip->position();

        if ($last !== null && $trip->last_ping_at !== null) {
            $elapsed = max(1, now()->diffInSeconds($trip->last_ping_at, absolute: true));
            $distance = Distance::between($last, $ping->coordinate);
            $impliedKmh = ($distance / $elapsed) * 3.6;

            if ($impliedKmh > $maxSpeed * 1.5) {
                throw DomainException::make('gps_jump_detected', 422, [
                    'implied_speed_kmh' => round($impliedKmh, 1),
                ]);
            }
        }
    }

    /** One accepted ping per trip per configured interval. */
    private function isThrottled(Trip $trip, LocationPing $ping): bool
    {
        $minInterval = (int) config('transit.gps.min_interval_seconds');

        if ($minInterval <= 0 || $trip->last_ping_at === null) {
            return false;
        }

        return $trip->last_ping_at->gt(now()->subSeconds($minInterval));
    }

    /**
     * Emit arrival and departure events, and feed the ETA engine the observed
     * time for each completed hop. Guarded by a cache marker so a bus idling
     * inside a geofence raises one arrival, not one per ping.
     */
    private function handleStopTransitions(Trip $trip, $match, ?RouteStop $atStop, ?int $previousStopId): void
    {
        foreach ($match->passedStops as $passed) {
            $this->events->record($trip, TripEventType::StopDeparted, [
                'stop_id' => $passed->bus_stop_id,
                'sequence' => $passed->sequence,
            ]);
        }

        if ($atStop === null) {
            if ($previousStopId !== null) {
                Cache::forget($this->arrivalMarkerKey($trip, $previousStopId));
            }

            return;
        }

        $marker = $this->arrivalMarkerKey($trip, $atStop->bus_stop_id);

        // add() returns false if the marker already exists: already arrived.
        if (! Cache::add($marker, now()->timestamp, 900)) {
            return;
        }

        $this->events->record($trip, TripEventType::StopArrived, [
            'stop_id' => $atStop->bus_stop_id,
            'sequence' => $atStop->sequence,
        ]);

        // Close the segment: how long did the hop from the previous stop take?
        $lastArrival = Cache::get($this->lastArrivalKey($trip));

        if (is_array($lastArrival) && $lastArrival['stop_id'] !== $atStop->bus_stop_id) {
            $this->eta->recordObservation(
                routeId: $trip->route_id,
                fromStopId: $lastArrival['stop_id'],
                toStopId: $atStop->bus_stop_id,
                seconds: now()->timestamp - $lastArrival['at'],
            );
        }

        Cache::put($this->lastArrivalKey($trip), [
            'stop_id' => $atStop->bus_stop_id,
            'at' => now()->timestamp,
        ], 3600);
    }

    /**
     * Off-route is only raised after several consecutive deviating pings: a
     * single bad fix beside a tall building must not page the control room.
     */
    private function handleOffRoute(Trip $trip, $match): void
    {
        $key = "trip:{$trip->id}:off_route_streak";
        $threshold = (int) config('transit.gps.off_route_ping_threshold');

        if ($match->isOffRoute) {
            $streak = (int) Cache::get($key, 0) + 1;
            Cache::put($key, $streak, 600);

            if ($streak >= $threshold && ! $trip->is_off_route) {
                $trip->forceFill(['is_off_route' => true])->save();

                $this->events->record($trip, TripEventType::OffRoute, [
                    'deviation_meters' => round($match->deviationMeters, 1),
                ]);
            }

            return;
        }

        Cache::forget($key);

        if ($trip->is_off_route) {
            $trip->forceFill(['is_off_route' => false])->save();
            $this->events->record($trip, TripEventType::BackOnRoute, []);
        }
    }

    /** Distinguish "stopped in traffic" from "stopped for good". */
    private function handleIdle(Trip $trip, LocationPing $ping): void
    {
        $idleSpeed = (float) config('transit.gps.idle_speed_kmh');
        $idleSeconds = (int) config('transit.gps.idle_seconds');
        $key = "trip:{$trip->id}:idle_since";

        $isSlow = ($ping->speedKmh ?? 0) <= $idleSpeed;

        if (! $isSlow) {
            Cache::forget($key);

            if ($trip->is_idle) {
                $trip->forceFill(['is_idle' => false])->save();
                $this->events->record($trip, TripEventType::IdleCleared, []);
            }

            return;
        }

        $since = Cache::get($key);

        if ($since === null) {
            Cache::put($key, now()->timestamp, 3600);

            return;
        }

        if (! $trip->is_idle && (now()->timestamp - $since) >= $idleSeconds) {
            $trip->forceFill(['is_idle' => true])->save();
            $this->events->record($trip, TripEventType::IdleDetected, [
                'idle_seconds' => now()->timestamp - $since,
            ]);
        }
    }

    private function refreshNextStopEta(Trip $trip): void
    {
        if ($trip->nextStop === null) {
            return;
        }

        $estimate = $this->eta->estimate($trip, $trip->nextStop);

        $trip->forceFill(['eta_next_stop_seconds' => $estimate?->seconds])->save();
    }

    /** @return array<string, mixed> */
    private function buildLiveState(Trip $trip, LocationPing $ping, $match): array
    {
        $trip->loadMissing(['bus:id,uuid,bus_number,capacity_seated,capacity_standing', 'line:id,code,name,color', 'nextStop:id,name', 'destinationStop:id,name']);

        return [
            'trip_uuid' => $trip->uuid,
            'trip_id' => $trip->id,
            'city_id' => $trip->city_id,
            'bus_uuid' => $trip->bus?->uuid,
            'bus_number' => $trip->bus?->bus_number,
            'line_id' => $trip->bus_line_id,
            'line_code' => $trip->line?->code,
            'line_name' => $trip->line?->name,
            'line_color' => $trip->line?->color,
            'route_id' => $trip->route_id,
            'lat' => round($ping->coordinate->lat, 6),
            'lng' => round($ping->coordinate->lng, 6),
            'speed' => $ping->speedKmh === null ? null : round($ping->speedKmh, 1),
            'heading' => $ping->heading === null ? null : round($ping->heading, 1),
            'passenger_count' => $trip->passenger_count,
            'occupancy' => $trip->occupancyRatio(),
            'next_stop' => $trip->nextStop?->name,
            'next_stop_id' => $trip->next_stop_id,
            'distance_to_next_stop' => $trip->distance_to_next_stop,
            'eta_next_stop_seconds' => $trip->eta_next_stop_seconds,
            'destination' => $trip->destinationStop?->name ?? $trip->line?->destination_label,
            'status' => $trip->status->value,
            'is_off_route' => $trip->is_off_route,
            'is_idle' => $trip->is_idle,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Tell the driver app how often to report next. Reporting every 5 seconds
     * while parked at a terminal wastes battery and data for no information
     * gain, so the cadence follows what the bus is actually doing.
     */
    private function acknowledgement(Trip $trip, bool $throttled = false): array
    {
        $cadence = (array) config('transit.gps.cadence');

        $interval = match (true) {
            $trip->is_idle => $cadence['idle'],
            $trip->distance_to_next_stop !== null && $trip->distance_to_next_stop < 300 => $cadence['approaching_stop'],
            default => $cadence['moving'],
        };

        return [
            'accepted' => ! $throttled,
            'next_ping_in' => $interval,
            'trip_status' => $trip->status->value,
            'passenger_count' => $trip->passenger_count,
            'next_stop' => $trip->nextStop?->name,
            'distance_to_next_stop' => $trip->distance_to_next_stop,
            'eta_next_stop_seconds' => $trip->eta_next_stop_seconds,
            'is_off_route' => $trip->is_off_route,
        ];
    }

    private function arrivalMarkerKey(Trip $trip, int $stopId): string
    {
        return "trip:{$trip->id}:arrived:$stopId";
    }

    private function lastArrivalKey(Trip $trip): string
    {
        return "trip:{$trip->id}:last_arrival";
    }
}
