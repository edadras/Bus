<?php

namespace App\Domain\Ridership\Services;

use App\Domain\Operations\Enums\TripEventType;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Services\RouteMatcher;
use App\Domain\Operations\Services\TripEventRecorder;
use App\Domain\Ridership\Enums\AlightingSource;
use App\Domain\Ridership\Enums\PassengerTripStatus;
use App\Domain\Ridership\Events\PassengerAlighted;
use App\Domain\Ridership\Models\PassengerAlighting;
use App\Domain\Ridership\Models\PassengerLocationPing;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use Illuminate\Support\Facades\DB;

/**
 * Deciding when a passenger got off.
 *
 * There is no sensor for this, only two noisy GPS traces — the bus's and the
 * phone's — so a single "they're 80m away" reading is not evidence: it is the
 * everyday behaviour of urban GPS. Closing a ride on one such reading would
 * free the seat while the passenger is still aboard and corrupt the occupancy
 * figure the driver is looking at.
 *
 * So each observation produces a *confidence*, accumulated from independent
 * signals, and a ride is only closed once the total clears the configured
 * threshold across at least two consecutive observations. Anything in between
 * parks the ride in `pending_alighting`: still counted aboard, but flagged.
 * The scheduler force-closes whatever is left after the trip ends, so nothing
 * stays open forever.
 *
 * Signals, all of which can be wrong alone and are rarely wrong together:
 *   - separation      : how far the phone is from the bus, scaled by accuracy
 *   - divergence      : separation growing over consecutive samples
 *   - stop proximity  : the passenger sits near a stop the bus has left
 *   - bus departed    : the bus has moved on along the route since the sample
 */
class AlightingService
{
    public function __construct(
        private readonly RouteMatcher $matcher,
        private readonly TripEventRecorder $events,
    ) {}

    /**
     * Record a passenger position sample and decide what it implies.
     *
     * @return array{status: string, confidence: float, closed: bool}
     */
    public function observe(
        PassengerTrip $passengerTrip,
        Coordinate $position,
        ?float $accuracyMeters = null,
    ): array {
        if (! $passengerTrip->isOpen()) {
            return ['status' => $passengerTrip->status->value, 'confidence' => 1.0, 'closed' => true];
        }

        $trip = $passengerTrip->trip;
        $busPosition = $trip?->position();

        if ($busPosition === null) {
            return ['status' => $passengerTrip->status->value, 'confidence' => 0.0, 'closed' => false];
        }

        $separation = Distance::between($position, $busPosition);

        $previous = $passengerTrip->locationPings()->latest('recorded_at')->first();

        PassengerLocationPing::create([
            'passenger_trip_id' => $passengerTrip->id,
            'user_id' => $passengerTrip->user_id,
            'lat' => $position->lat,
            'lng' => $position->lng,
            'accuracy_meters' => $accuracyMeters,
            'distance_to_bus_meters' => round($separation, 2),
            'recorded_at' => now(),
        ]);

        $signals = $this->scoreSignals($passengerTrip, $trip, $position, $separation, $accuracyMeters, $previous);
        $confidence = $this->combine($signals);

        $threshold = (float) config('transit.ridership.alighting_confidence_threshold');

        if ($confidence < 0.35) {
            // Clearly still aboard; clear any earlier suspicion.
            if ($passengerTrip->status === PassengerTripStatus::PendingAlighting) {
                $passengerTrip->forceFill(['status' => PassengerTripStatus::Active])->save();
            }

            return ['status' => PassengerTripStatus::Active->value, 'confidence' => $confidence, 'closed' => false];
        }

        $stopId = $this->nearestPassedStop($trip, $position);

        $detection = PassengerAlighting::create([
            'passenger_trip_id' => $passengerTrip->id,
            'trip_id' => $passengerTrip->trip_id,
            'user_id' => $passengerTrip->user_id,
            'bus_stop_id' => $stopId,
            'source' => AlightingSource::Geofence,
            'confidence' => round($confidence, 3),
            'signals' => $signals,
            'lat' => $position->lat,
            'lng' => $position->lng,
            'distance_to_bus_meters' => round($separation, 2),
            'detected_at' => now(),
        ]);

        // Corroboration requirement: one confident sample is suggestive, two
        // consecutive ones are a decision.
        $confidentCount = $passengerTrip->alightings()
            ->where('confidence', '>=', $threshold)
            ->count();

        $required = (int) config('transit.ridership.alighting_signal_threshold');

        if ($confidence >= $threshold && $confidentCount >= $required) {
            $detection->forceFill(['confirmed_at' => now()])->save();

            $this->close($passengerTrip, AlightingSource::Geofence, $confidence, $stopId);

            return ['status' => PassengerTripStatus::Completed->value, 'confidence' => $confidence, 'closed' => true];
        }

        $passengerTrip->forceFill([
            'status' => PassengerTripStatus::PendingAlighting,
            'alighting_confidence' => round($confidence, 3),
        ])->save();

        return [
            'status' => PassengerTripStatus::PendingAlighting->value,
            'confidence' => $confidence,
            'closed' => false,
        ];
    }

    /**
     * Score each independent signal in [0,1].
     *
     * @return array<string, float|int|bool>
     */
    private function scoreSignals(
        PassengerTrip $passengerTrip,
        ?Trip $trip,
        Coordinate $position,
        float $separation,
        ?float $accuracy,
        ?PassengerLocationPing $previous,
    ): array {
        // Discount the reading by its own error: a 100m separation reported
        // with 90m accuracy tells us almost nothing.
        $effectiveSeparation = max(0.0, $separation - ($accuracy ?? 0) * 0.5);

        // Ramp from 0 at 50m to 1 at 250m; inside a long bus, 50m is normal.
        $separationScore = min(1.0, max(0.0, ($effectiveSeparation - 50) / 200));

        // Growing separation is the strongest single indicator of leaving.
        $divergenceScore = 0.0;
        if ($previous !== null && $previous->distance_to_bus_meters !== null) {
            $growth = $separation - $previous->distance_to_bus_meters;
            $divergenceScore = min(1.0, max(0.0, $growth / 150));
        }

        // Standing near a stop the bus has already served is consistent with
        // having just got off at that stop.
        $stopScore = 0.0;
        $nearStop = $trip?->route !== null ? $this->matcher->stopAt($trip->route, $position) : null;
        if ($nearStop !== null && $trip !== null
            && $nearStop->distance_from_start <= $trip->route_offset_meters) {
            $stopScore = 1.0;
        }

        // The bus has driven on since this sample was taken.
        $departedScore = 0.0;
        if ($trip !== null && $trip->current_speed_kmh !== null && $trip->current_speed_kmh > 15 && $separation > 120) {
            $departedScore = 1.0;
        }

        return [
            'separation_meters' => round($separation, 1),
            'effective_separation' => round($effectiveSeparation, 1),
            'accuracy_meters' => $accuracy,
            'separation_score' => round($separationScore, 3),
            'divergence_score' => round($divergenceScore, 3),
            'stop_proximity_score' => $stopScore,
            'bus_departed_score' => $departedScore,
        ];
    }

    /**
     * Weighted blend. Weights sum to 1; separation dominates but cannot alone
     * reach the 0.75 threshold, so a single noisy fix can never close a ride.
     */
    private function combine(array $signals): float
    {
        $score = 0.45 * $signals['separation_score']
            + 0.25 * $signals['divergence_score']
            + 0.15 * $signals['stop_proximity_score']
            + 0.15 * $signals['bus_departed_score'];

        return round(min(1.0, max(0.0, $score)), 3);
    }

    /** Close the ride and free the seat. Idempotent. */
    public function close(
        PassengerTrip $passengerTrip,
        AlightingSource $source,
        float $confidence = 1.0,
        ?int $stopId = null,
    ): PassengerTrip {
        if (! $passengerTrip->isOpen()) {
            return $passengerTrip;
        }

        return DB::transaction(function () use ($passengerTrip, $source, $confidence, $stopId): PassengerTrip {
            $trip = $passengerTrip->trip;

            $distance = null;
            if ($trip !== null && $passengerTrip->boarding_stop_id !== null) {
                $distance = $this->travelledDistance($passengerTrip, $trip, $stopId);
            }

            $passengerTrip->forceFill([
                'status' => $source === AlightingSource::Timeout
                    ? PassengerTripStatus::ForceClosed
                    : PassengerTripStatus::Completed,
                'alighted_at' => now(),
                'alighting_stop_id' => $stopId,
                'alighting_confidence' => round($confidence, 3),
                'alighting_source' => $source->value,
                'duration_seconds' => (int) $passengerTrip->boarded_at->diffInSeconds(now()),
                'distance_meters' => $distance,
            ])->save();

            if ($trip !== null) {
                $locked = Trip::whereKey($trip->id)->lockForUpdate()->first();

                $count = max(0, $locked->passenger_count - 1);
                $locked->forceFill(['passenger_count' => $count])->save();

                $this->events->record($locked, TripEventType::PassengerAlighted, [
                    'stop_id' => $stopId,
                    'passenger_count' => $count,
                    'source' => $source->value,
                ]);

                PassengerAlighted::dispatch($locked->id, $locked->city_id, $count, $passengerTrip->uuid);
            }

            return $passengerTrip;
        });
    }

    /** Passenger-initiated "I've got off" from the app. Always authoritative. */
    public function closeManually(PassengerTrip $passengerTrip, ?Coordinate $position = null): PassengerTrip
    {
        $stopId = $position !== null ? $this->nearestPassedStop($passengerTrip->trip, $position) : null;

        return $this->close($passengerTrip, AlightingSource::Manual, 1.0, $stopId);
    }

    /**
     * Rides still open long after their bus finished. Without this a forgotten
     * ride would block the passenger's next boarding indefinitely.
     */
    public function closeAbandoned(): int
    {
        $cutoff = now()->subMinutes((int) config('transit.ridership.auto_close_after_minutes'));

        $stale = PassengerTrip::query()
            ->open()
            ->where('boarded_at', '<=', $cutoff)
            ->with('trip')
            ->limit(500)
            ->get();

        foreach ($stale as $passengerTrip) {
            $this->close(
                $passengerTrip,
                AlightingSource::Timeout,
                confidence: 0.0,
                stopId: $passengerTrip->trip?->destination_stop_id,
            );
        }

        return $stale->count();
    }

    private function nearestPassedStop(?Trip $trip, Coordinate $position): ?int
    {
        if ($trip?->route === null) {
            return null;
        }

        return $this->matcher->stopAt($trip->route, $position)?->bus_stop_id
            ?? $trip->current_stop_id;
    }

    private function travelledDistance(PassengerTrip $passengerTrip, Trip $trip, ?int $alightingStopId): ?int
    {
        $stops = $this->matcher->stopsFor($trip->route)->keyBy('bus_stop_id');

        $from = $stops->get($passengerTrip->boarding_stop_id)?->distance_from_start;
        $to = $alightingStopId !== null
            ? $stops->get($alightingStopId)?->distance_from_start
            : $trip->route_offset_meters;

        return $from === null || $to === null ? null : max(0, (int) ($to - $from));
    }
}
