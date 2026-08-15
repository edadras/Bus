<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fleet\Enums\ShiftStatus;
use App\Domain\Fleet\Models\DriverShift;
use App\Domain\Fleet\Services\BusQrService;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Operations\DTO\LocationPing;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Services\LocationIngestService;
use App\Domain\Operations\Services\TripService;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Driver\LocationRequest;
use App\Http\Requests\V1\Driver\StartShiftRequest;
use App\Http\Requests\V1\Driver\StartTripRequest;
use App\Http\Resources\V1\BusResource;
use App\Http\Resources\V1\RouteResource;
use App\Http\Resources\V1\TripResource;
use App\Support\Api\ApiResponse;
use App\Support\Exceptions\DomainException;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The driver app's API.
 *
 * Every action re-derives the driver from the authenticated user rather than
 * trusting an id in the request, and the authorisation rule ("may this driver
 * operate this bus today") lives in the domain, not here.
 */
class DriverController extends Controller
{
    public function __construct(
        private readonly TripService $trips,
        private readonly BusQrService $qr,
        private readonly LocationIngestService $ingest,
    ) {}

    /** Everything the driver app needs on launch. */
    public function state(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $shift = $driver->openShift();
        $trip = $shift === null
            ? null
            : Trip::where('driver_shift_id', $shift->id)->live()->latest('started_at')->first();

        $assignments = $driver->assignments()->current()->with(['bus', 'line'])->get();

        return ApiResponse::success([
            'driver' => [
                'uuid' => $driver->uuid,
                'name' => $driver->user?->name,
                'status' => $driver->status->value,
                'employee_code' => $driver->employee_code,
                'total_trips' => $driver->total_trips,
                'license_expires_at' => $driver->license_expires_at?->toDateString(),
            ],
            'assigned_buses' => $assignments->map(fn ($assignment) => [
                'bus' => (new BusResource($assignment->bus))->resolve(),
                'line' => $assignment->line === null ? null : [
                    'id' => $assignment->line->id,
                    'code' => $assignment->line->code,
                    'name' => $assignment->line->name,
                ],
            ])->values(),
            'shift' => $shift === null ? null : $this->shiftPayload($shift),
            'trip' => $trip === null ? null : (new TripResource(
                $trip->load(['bus', 'line', 'nextStop', 'destinationStop'])
            ))->resolve(),
        ]);
    }

    /** Scan the bus QR to begin a shift. */
    public function startShift(StartShiftRequest $request): JsonResponse
    {
        $driver = $this->driver($request);

        $resolved = $this->qr->resolveScan($request->string('token')->toString());

        $shift = $this->trips->startShift(
            driver: $driver,
            bus: $resolved['bus'],
            lat: $request->input('lat'),
            lng: $request->input('lng'),
        );

        $routes = BusRoute::query()
            ->active()
            ->whereHas('line', fn ($q) => $q->where('city_id', $driver->city_id)->where('is_active', true))
            ->with(['line', 'originStop', 'destinationStop'])
            ->get();

        return ApiResponse::success([
            'shift' => $this->shiftPayload($shift),
            'bus' => (new BusResource($resolved['bus']))->resolve(),
            // The driver picks which route they are running this shift.
            'available_routes' => RouteResource::collection($routes)->resolve(),
        ], status: 201);
    }

    public function endShift(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $shift = $driver->openShift();

        if ($shift === null) {
            throw DomainException::make('no_open_shift', 422);
        }

        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $shift = $this->trips->endShift($shift, $validated['lat'] ?? null, $validated['lng'] ?? null);

        return ApiResponse::success($this->shiftPayload($shift));
    }

    public function startTrip(StartTripRequest $request): JsonResponse
    {
        $driver = $this->driver($request);
        $shift = $driver->openShift();

        if ($shift === null) {
            throw DomainException::make('no_open_shift', 422);
        }

        $route = BusRoute::with('line')->findOrFail($request->integer('route_id'));

        abort_unless($route->line?->city_id === $driver->city_id, 404);

        $trip = $this->trips->start($shift, $route);

        return ApiResponse::success(
            (new TripResource($trip->load(['bus', 'line', 'nextStop', 'destinationStop'])))->resolve(),
            status: 201,
        );
    }

    /**
     * The GPS firehose. Returns the cadence the app should use next, so the
     * server controls battery cost centrally instead of every app guessing.
     */
    public function location(LocationRequest $request): JsonResponse
    {
        $trip = $this->activeTrip($request);

        $result = $this->ingest->ingest($trip, LocationPing::fromArray($request->validated()));

        return ApiResponse::success($result);
    }

    public function pauseTrip(Request $request): JsonResponse
    {
        return ApiResponse::success(
            (new TripResource($this->trips->pause($this->activeTrip($request))))->resolve(),
        );
    }

    public function resumeTrip(Request $request): JsonResponse
    {
        return ApiResponse::success(
            (new TripResource($this->trips->resume($this->activeTrip($request))))->resolve(),
        );
    }

    public function completeTrip(Request $request): JsonResponse
    {
        return ApiResponse::success(
            (new TripResource($this->trips->complete($this->activeTrip($request))))->resolve(),
        );
    }

    /** Who is aboard, as a count plus recent boardings — never identities. */
    public function passengers(Request $request): JsonResponse
    {
        $trip = $this->activeTrip($request);

        $recent = PassengerTrip::where('trip_id', $trip->id)
            ->orderByDesc('boarded_at')
            ->limit(20)
            ->get(['uuid', 'boarded_at', 'fare_amount', 'status', 'boarding_stop_id']);

        return ApiResponse::success([
            'passenger_count' => $trip->passenger_count,
            'peak_passenger_count' => $trip->peak_passenger_count,
            'boarding_count' => $trip->boarding_count,
            'capacity' => $trip->bus?->totalCapacity(),
            'occupancy' => $trip->occupancyRatio(),
            'revenue' => [
                'amount' => $trip->revenue_minor,
                'formatted' => Money::format($trip->revenue_minor),
            ],
            'recent_boardings' => $recent->map(fn ($ride) => [
                'uuid' => $ride->uuid,
                'boarded_at' => $ride->boarded_at?->toIso8601String(),
                'fare' => $ride->fare_amount,
                'status' => $ride->status->value,
            ])->values(),
            'channel' => config('transit.live.channel_prefix').'.trip.'.$trip->id.'.crew',
        ]);
    }

    /** The route ahead, with the bus's current position marked. */
    public function currentRoute(Request $request): JsonResponse
    {
        $trip = $this->activeTrip($request);
        $trip->load(['route.routeStops.stop']);

        return ApiResponse::success([
            'route' => (new RouteResource($trip->route))->resolve(),
            'stops' => $trip->route?->routeStops->map(fn ($routeStop) => [
                'sequence' => $routeStop->sequence,
                'name' => $routeStop->stop?->name,
                'lat' => $routeStop->stop?->lat,
                'lng' => $routeStop->stop?->lng,
                'distance_from_start' => $routeStop->distance_from_start,
                'passed' => $routeStop->distance_from_start <= $trip->route_offset_meters,
                'is_next' => $routeStop->bus_stop_id === $trip->next_stop_id,
            ])->values() ?? [],
            'position' => [
                'lat' => $trip->current_lat,
                'lng' => $trip->current_lng,
                'offset_meters' => $trip->route_offset_meters,
            ],
        ]);
    }

    private function driver(Request $request): \App\Domain\Fleet\Models\Driver
    {
        $driver = $request->user()->driver;

        if ($driver === null) {
            throw DomainException::make('not_a_driver', 403);
        }

        if (! $driver->status->canDrive()) {
            throw DomainException::make('driver_not_active', 403, ['status' => $driver->status->value]);
        }

        return $driver;
    }

    private function activeTrip(Request $request): Trip
    {
        $driver = $this->driver($request);

        $trip = Trip::where('driver_id', $driver->id)
            ->live()
            ->with(['bus', 'route', 'line', 'nextStop'])
            ->latest('started_at')
            ->first();

        if ($trip === null) {
            throw DomainException::make('no_active_trip', 422);
        }

        return $trip;
    }

    private function shiftPayload(DriverShift $shift): array
    {
        return [
            'id' => $shift->id,
            'status' => $shift->status->value,
            'started_at' => $shift->started_at?->toIso8601String(),
            'ended_at' => $shift->ended_at?->toIso8601String(),
            'duration_minutes' => $shift->durationMinutes(),
            'trip_count' => $shift->trip_count,
            'passenger_count' => $shift->passenger_count,
            'distance_meters' => $shift->distance_meters,
            'revenue' => [
                'amount' => $shift->revenue_minor,
                'formatted' => Money::format($shift->revenue_minor),
            ],
            'bus' => $shift->bus === null ? null : [
                'uuid' => $shift->bus->uuid,
                'number' => $shift->bus->bus_number,
            ],
        ];
    }
}
