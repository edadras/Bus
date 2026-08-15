<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Services\AuditLogger;
use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Enums\RouteDirection;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use App\Domain\Operations\Services\RouteMatcher;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BusLineResource;
use App\Http\Resources\V1\BusStopResource;
use App\Http\Resources\V1\RouteResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NetworkAdminController extends Controller
{
    public function __construct(
        private readonly RouteMatcher $matcher,
        private readonly AuditLogger $audit,
    ) {}

    public function storeStop(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32',
                Rule::unique('bus_stops')->where(fn ($q) => $q->where('city_id', $this->city()->id))],
            'name' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'geofence_radius' => ['nullable', 'integer', 'min:20', 'max:500'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'is_terminal' => ['boolean'],
            'is_accessible' => ['boolean'],
            'has_shelter' => ['boolean'],
            'provenance' => ['nullable', Rule::in(NetworkProvenance::values())],
        ]);

        $stop = BusStop::create($validated + [
            'city_id' => $this->city()->id,
            'provenance' => $validated['provenance'] ?? NetworkProvenance::Official->value,
        ]);

        $this->audit->log('network.stop.created', $stop, $request->user(), after: $validated);

        return ApiResponse::success((new BusStopResource($stop))->resolve(), status: 201);
    }

    public function updateStop(Request $request, BusStop $stop): JsonResponse
    {
        abort_unless($stop->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'geofence_radius' => ['sometimes', 'integer', 'min:20', 'max:500'],
            'is_active' => ['boolean'],
            'is_terminal' => ['boolean'],
            'is_accessible' => ['boolean'],
            'has_shelter' => ['boolean'],
            'provenance' => ['sometimes', Rule::in(NetworkProvenance::values())],
        ]);

        $movedPosition = isset($validated['lat']) || isset($validated['lng']);

        $stop->fill($validated);
        $this->audit->logChange('network.stop.updated', $stop, $request->user());
        $stop->save();

        // Moving a stop invalidates every route offset that referenced it, and
        // stale offsets silently break both "next stop" and every ETA.
        if ($movedPosition) {
            foreach (BusRoute::whereHas('routeStops', fn ($q) => $q->where('bus_stop_id', $stop->id))->get() as $route) {
                $this->matcher->recalculateStopOffsets($route);
            }
        }

        return ApiResponse::success((new BusStopResource($stop))->resolve());
    }

    public function storeLine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32',
                Rule::unique('bus_lines')->where(fn ($q) => $q->where('city_id', $this->city()->id))],
            'name' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'origin_label' => ['nullable', 'string', 'max:150'],
            'destination_label' => ['nullable', 'string', 'max:150'],
            'typical_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'headway_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'service_start' => ['nullable', 'date_format:H:i'],
            'service_end' => ['nullable', 'date_format:H:i'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $line = BusLine::create($validated + [
            'city_id' => $this->city()->id,
            'provenance' => NetworkProvenance::Official->value,
        ]);

        $this->audit->log('network.line.created', $line, $request->user(), after: $validated);

        return ApiResponse::success((new BusLineResource($line))->resolve(), status: 201);
    }

    public function updateLine(Request $request, BusLine $line): JsonResponse
    {
        abort_unless($line->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'origin_label' => ['nullable', 'string', 'max:150'],
            'destination_label' => ['nullable', 'string', 'max:150'],
            'typical_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'headway_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $line->fill($validated);
        $this->audit->logChange('network.line.updated', $line, $request->user());
        $line->save();

        return ApiResponse::success((new BusLineResource($line))->resolve());
    }

    /**
     * Create or replace a route's stop sequence. Offsets are recalculated in
     * the same transaction, so a route is never left half-updated with ETA
     * maths pointing at the old geometry.
     */
    public function storeRoute(Request $request, BusLine $line): JsonResponse
    {
        abort_unless($line->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'direction' => ['required', Rule::in(RouteDirection::values())],
            'is_default' => ['boolean'],
            'geometry' => ['nullable', 'array', 'min:2'],
            'geometry.*.lat' => ['required_with:geometry', 'numeric', 'between:-90,90'],
            'geometry.*.lng' => ['required_with:geometry', 'numeric', 'between:-180,180'],
            'stops' => ['required', 'array', 'min:2'],
            'stops.*.bus_stop_id' => ['required', 'integer', 'exists:bus_stops,id'],
            'stops.*.dwell_seconds' => ['nullable', 'integer', 'min:0', 'max:600'],
            'stops.*.is_timepoint' => ['boolean'],
        ]);

        $route = DB::transaction(function () use ($validated, $line): BusRoute {
            $stopIds = array_column($validated['stops'], 'bus_stop_id');

            $route = $line->routes()->create([
                'name' => $validated['name'],
                'direction' => $validated['direction'],
                'geometry' => $validated['geometry'] ?? null,
                'is_default' => $validated['is_default'] ?? false,
                'is_active' => true,
                'provenance' => NetworkProvenance::Official->value,
                'origin_stop_id' => $stopIds[0],
                'destination_stop_id' => end($stopIds),
            ]);

            foreach ($validated['stops'] as $index => $stop) {
                $route->routeStops()->create([
                    'bus_stop_id' => $stop['bus_stop_id'],
                    'sequence' => $index + 1,
                    'dwell_seconds' => $stop['dwell_seconds'] ?? 20,
                    'is_timepoint' => $stop['is_timepoint'] ?? false,
                ]);
            }

            $this->matcher->recalculateStopOffsets($route);

            return $route;
        });

        $this->audit->log('network.route.created', $route, $request->user(), context: [
            'line' => $line->code,
            'stops' => count($validated['stops']),
        ]);

        // The ends are loaded too: a create response that omits what the same
        // route reports everywhere else makes the caller fetch it again.
        return ApiResponse::success(
            (new RouteResource($route->load(['routeStops.stop', 'originStop', 'destinationStop'])))->resolve(),
            status: 201,
        );
    }

    /** Re-snap every stop after a geometry edit or a bulk import. */
    public function recalculateRoute(Request $request, BusRoute $route): JsonResponse
    {
        abort_unless($route->line?->city_id === $this->city()->id, 404);

        $updated = $this->matcher->recalculateStopOffsets($route);

        $this->audit->log('network.route.recalculated', $route, $request->user(), context: [
            'stops_updated' => $updated,
        ]);

        return ApiResponse::success([
            'stops_updated' => $updated,
            'distance_meters' => $route->fresh()->distance_meters,
        ]);
    }
}
