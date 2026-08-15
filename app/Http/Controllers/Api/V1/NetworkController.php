<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mapping\Contracts\MapProvider;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use App\Domain\Operations\Services\EtaEngine;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BusLineResource;
use App\Http\Resources\V1\BusStopResource;
use App\Http\Resources\V1\LineSummaryResource;
use App\Http\Resources\V1\RouteResource;
use App\Support\Api\ApiResponse;
use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public network browsing. Every endpoint here is readable by a guest: a
 * passenger must be able to plan a journey before they have an account.
 */
class NetworkController extends Controller
{
    public function __construct(private readonly EtaEngine $eta) {}

    public function stops(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'integer', 'min:50', 'max:10000'],
            'q' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 30);

        $query = BusStop::query()
            ->forCity($this->city())
            ->active();

        if (isset($validated['q'])) {
            $term = '%'.$validated['q'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }

        // Nearby search: bounding box in SQL, exact distance sort in PHP. The
        // box is what makes the composite index usable.
        if (isset($validated['lat'], $validated['lng'])) {
            $center = new Coordinate((float) $validated['lat'], (float) $validated['lng']);
            $radius = (int) ($validated['radius'] ?? 800);

            $stops = $query->near($center, $radius)->limit(200)->get()
                ->map(function (BusStop $stop) use ($center) {
                    $stop->setAttribute('distance_meters', (int) round($stop->distanceTo($center)));

                    return $stop;
                })
                ->filter(fn (BusStop $stop) => $stop->distance_meters <= $radius)
                ->sortBy(fn (BusStop $stop) => $stop->distance_meters)
                ->take($limit)
                ->values();

            return ApiResponse::success(BusStopResource::collection($stops)->resolve(), [
                'center' => $center->toArray(),
                'radius' => $radius,
            ]);
        }

        return ApiResponse::success(
            BusStopResource::collection($query->orderBy('name')->limit($limit)->get())->resolve(),
        );
    }

    public function stop(BusStop $stop): JsonResponse
    {
        abort_unless($stop->city_id === $this->city()->id, 404);

        $lines = $stop->lines()->get();

        return ApiResponse::success([
            'stop' => (new BusStopResource($stop))->resolve(),
            'lines' => LineSummaryResource::collection($lines)->resolve(),
        ]);
    }

    /** The arrival board a passenger sees while standing at a stop. */
    public function arrivals(Request $request, BusStop $stop): JsonResponse
    {
        abort_unless($stop->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'line_id' => ['nullable', 'integer', 'exists:bus_lines,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $arrivals = $this->eta->arrivalsForStop(
            $stop,
            isset($validated['line_id']) ? (int) $validated['line_id'] : null,
            (int) ($validated['limit'] ?? 10),
        );

        return ApiResponse::success($arrivals, [
            'stop' => ['id' => $stop->id, 'name' => $stop->name, 'code' => $stop->code],
            'generated_at' => now()->toIso8601String(),
            'is_verified_data' => $stop->provenance->isVerified(),
        ]);
    }

    public function lines(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:80']]);

        $lines = BusLine::query()
            ->forCity($this->city())
            ->active()
            ->when(isset($validated['q']), function ($query) use ($validated) {
                $term = '%'.$validated['q'].'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->orderBy('code')
            ->get();

        return ApiResponse::success(BusLineResource::collection($lines)->resolve());
    }

    public function line(BusLine $line): JsonResponse
    {
        abort_unless($line->city_id === $this->city()->id, 404);

        $line->load(['routes' => fn ($q) => $q->active(), 'routes.originStop', 'routes.destinationStop']);

        return ApiResponse::success((new BusLineResource($line))->resolve());
    }

    public function route(Request $request, BusRoute $route): JsonResponse
    {
        $route->load(['routeStops.stop', 'originStop', 'destinationStop', 'line']);

        abort_unless($route->line?->city_id === $this->city()->id, 404);

        return ApiResponse::success((new RouteResource($route))->resolve());
    }

    /** Tile configuration, so clients never hard code a map provider. */
    public function mapConfig(MapProvider $maps): JsonResponse
    {
        $city = $this->city();

        return ApiResponse::success([
            'provider' => $maps->tileConfig(),
            'center' => ['lat' => $city->center_lat, 'lng' => $city->center_lng],
            'zoom' => $city->default_zoom,
            'bounds' => $city->bbox_min_lat === null ? null : [
                'min_lat' => $city->bbox_min_lat,
                'min_lng' => $city->bbox_min_lng,
                'max_lat' => $city->bbox_max_lat,
                'max_lng' => $city->bbox_max_lng,
            ],
            'supports_routing' => $maps->supportsRouting(),
        ]);
    }

    /**
     * Simple journey suggestion: find stops near origin and destination, then
     * surface lines that serve both, in order. This is the v1 planner — it
     * covers direct journeys and explicitly reports when none exists rather
     * than inventing a transfer it cannot yet compute.
     */
    public function plan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_lat' => ['required', 'numeric', 'between:-90,90'],
            'from_lng' => ['required', 'numeric', 'between:-180,180'],
            'to_lat' => ['required', 'numeric', 'between:-90,90'],
            'to_lng' => ['required', 'numeric', 'between:-180,180'],
            'walk_radius' => ['nullable', 'integer', 'min:100', 'max:2000'],
        ]);

        $from = new Coordinate((float) $validated['from_lat'], (float) $validated['from_lng']);
        $to = new Coordinate((float) $validated['to_lat'], (float) $validated['to_lng']);
        $radius = (int) ($validated['walk_radius'] ?? 700);

        $originStops = $this->stopsNear($from, $radius);
        $destinationStops = $this->stopsNear($to, $radius);

        if ($originStops->isEmpty() || $destinationStops->isEmpty()) {
            return ApiResponse::success([
                'options' => [],
                'reason' => 'no_stop_within_walking_distance',
                'supports_transfers' => false,
            ]);
        }

        $originIds = $originStops->pluck('id');
        $destinationIds = $destinationStops->pluck('id');

        // A route serves the journey when it calls at an origin stop and then,
        // later in its sequence, at a destination stop.
        $routes = BusRoute::query()
            ->active()
            ->whereHas('line', fn ($q) => $q->where('city_id', $this->city()->id)->where('is_active', true))
            ->whereHas('routeStops', fn ($q) => $q->whereIn('bus_stop_id', $originIds))
            ->whereHas('routeStops', fn ($q) => $q->whereIn('bus_stop_id', $destinationIds))
            ->with(['line', 'routeStops.stop'])
            ->get();

        $options = $routes->map(function (BusRoute $route) use ($originIds, $destinationIds, $from, $to) {
            $boarding = $route->routeStops->first(fn ($rs) => $originIds->contains($rs->bus_stop_id));
            $alighting = $route->routeStops->last(fn ($rs) => $destinationIds->contains($rs->bus_stop_id));

            if ($boarding === null || $alighting === null || $alighting->sequence <= $boarding->sequence) {
                return null;
            }

            $rideDistance = max(0, $alighting->distance_from_start - $boarding->distance_from_start);
            $walkToStop = Distance::between($from, $boarding->stop->coordinate());
            $walkFromStop = Distance::between($to, $alighting->stop->coordinate());

            return [
                'line' => [
                    'id' => $route->line->id,
                    'code' => $route->line->code,
                    'name' => $route->line->name,
                    'color' => $route->line->color,
                ],
                'route_id' => $route->id,
                'board_at' => ['id' => $boarding->stop->id, 'name' => $boarding->stop->name],
                'alight_at' => ['id' => $alighting->stop->id, 'name' => $alighting->stop->name],
                'stops_count' => $alighting->sequence - $boarding->sequence,
                'ride_distance_meters' => $rideDistance,
                'walk_to_stop_meters' => (int) round($walkToStop),
                'walk_from_stop_meters' => (int) round($walkFromStop),
                // Walking at ~5 km/h plus the ride at the route baseline speed.
                'estimated_total_minutes' => (int) ceil(
                    ($walkToStop + $walkFromStop) / 83
                    + $rideDistance / (((float) config('transit.eta.baseline_speed_kmh')) * 1000 / 60)
                ),
            ];
        })->filter()->sortBy('estimated_total_minutes')->values();

        return ApiResponse::success([
            'options' => $options->all(),
            'reason' => $options->isEmpty() ? 'no_direct_line' : null,
            // Transfers arrive in v2; saying so beats silently returning none.
            'supports_transfers' => false,
        ]);
    }

    private function stopsNear(Coordinate $center, int $radius)
    {
        return BusStop::query()
            ->forCity($this->city())
            ->active()
            ->near($center, $radius)
            ->limit(50)
            ->get()
            ->filter(fn (BusStop $stop) => $stop->distanceTo($center) <= $radius)
            ->values();
    }
}
