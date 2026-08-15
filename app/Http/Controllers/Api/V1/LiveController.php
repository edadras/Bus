<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Network\Models\BusStop;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Services\EtaEngine;
use App\Domain\Operations\Services\LiveStateStore;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TripResource;
use App\Support\Api\ApiResponse;
use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live map. Reads come from the in-memory live state rather than the trips
 * table, so the cost of a thousand map viewers is a thousand cache reads, not
 * a thousand table scans. WebSocket subscribers get the same payload pushed.
 */
class LiveController extends Controller
{
    public function __construct(
        private readonly LiveStateStore $liveState,
        private readonly EtaEngine $eta,
    ) {}

    public function buses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'line_id' => ['nullable', 'integer'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'integer', 'min:100', 'max:50000'],
        ]);

        $buses = collect($this->liveState->forCity($this->city()->id));

        if (isset($validated['line_id'])) {
            $buses = $buses->where('line_id', (int) $validated['line_id']);
        }

        if (isset($validated['lat'], $validated['lng'])) {
            $center = new Coordinate((float) $validated['lat'], (float) $validated['lng']);
            $radius = (int) ($validated['radius'] ?? 3000);

            $buses = $buses->filter(
                fn (array $bus) => Distance::between($center, new Coordinate($bus['lat'], $bus['lng'])) <= $radius
            );
        }

        return ApiResponse::success($buses->values()->all(), [
            'count' => $buses->count(),
            'channel' => config('transit.live.channel_prefix').'.city.'.$this->city()->id.'.buses',
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function trip(Trip $trip): JsonResponse
    {
        abort_unless($trip->city_id === $this->city()->id, 404);

        $trip->load(['bus', 'line', 'route.routeStops.stop', 'originStop', 'destinationStop', 'currentStop', 'nextStop']);

        $stops = $trip->route?->routeStops ?? collect();

        return ApiResponse::success([
            'trip' => (new TripResource($trip))->resolve(),
            // The full stop list with a progress marker, so the passenger app
            // can draw "you are here" along the line.
            'progress' => $stops->map(fn ($routeStop) => [
                'sequence' => $routeStop->sequence,
                'stop_id' => $routeStop->bus_stop_id,
                'name' => $routeStop->stop?->name,
                'distance_from_start' => $routeStop->distance_from_start,
                'passed' => $routeStop->distance_from_start <= $trip->route_offset_meters,
                'is_next' => $routeStop->bus_stop_id === $trip->next_stop_id,
            ])->values()->all(),
            'channel' => config('transit.live.channel_prefix').'.trip.'.$trip->id,
        ]);
    }

    /** ETA of one specific trip to one specific stop. */
    public function tripEta(Trip $trip, BusStop $stop): JsonResponse
    {
        abort_unless($trip->city_id === $this->city()->id, 404);

        $estimate = $this->eta->estimate($trip, $stop);

        if ($estimate === null) {
            return ApiResponse::error('eta_unavailable', null, 404, [
                'reason' => $trip->status->acceptsTelemetry() ? 'stop_already_passed' : 'trip_not_live',
            ]);
        }

        return ApiResponse::success($estimate->toArray());
    }

    /** Headline counters for the landing page and passenger home screen. */
    public function summary(): JsonResponse
    {
        $cityId = $this->city()->id;
        $live = $this->liveState->forCity($cityId);

        return ApiResponse::success([
            'active_buses' => count($live),
            'lines' => \App\Domain\Network\Models\BusLine::forCity($cityId)->active()->count(),
            'stops' => BusStop::forCity($cityId)->active()->count(),
            'passengers_on_board' => array_sum(array_column($live, 'passenger_count')),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
