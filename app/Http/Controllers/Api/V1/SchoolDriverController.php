<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fleet\Models\Driver;
use App\Domain\SchoolTransport\Models\SchoolTrip;
use App\Domain\SchoolTransport\Models\SchoolTripStudent;
use App\Domain\SchoolTransport\Services\SchoolAttendanceService;
use App\Domain\SchoolTransport\Services\SchoolLiveService;
use App\Domain\SchoolTransport\Services\SchoolTripService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SchoolTripResource;
use App\Http\Resources\V1\SchoolTripStudentResource;
use App\Support\Api\ApiResponse;
use App\Support\Exceptions\DomainException;
use App\Support\Geo\Coordinate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The school driver app's API.
 *
 * The manifest is the app: a list of children in the order they are collected,
 * with an address, a door and two buttons. Everything else on this controller
 * exists to keep that list truthful.
 */
class SchoolDriverController extends Controller
{
    public function __construct(
        private readonly SchoolTripService $trips,
        private readonly SchoolAttendanceService $attendance,
        private readonly SchoolLiveService $live,
    ) {}

    /** Today's runs, in the order they happen. */
    public function state(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $date = $request->date('date') ?? today();

        $trips = $this->trips->forDriver($driver, $date);

        return ApiResponse::success([
            'driver' => [
                'uuid' => $driver->uuid,
                'name' => $driver->user?->name,
                'blocker' => $driver->personalBlocker(),
            ],
            'date' => $date->toDateString(),
            'trips' => SchoolTripResource::collection(
                $trips->loadMissing(['route.school', 'vehicle'])
            )->resolve(),
            'live_trip' => $trips->firstWhere('status.value', 'in_progress')?->uuid,
        ]);
    }

    /** One run with its manifest. */
    public function show(Request $request, SchoolTrip $schoolTrip): JsonResponse
    {
        $this->assertOwnTrip($request, $schoolTrip);

        $schoolTrip->load([
            'route.school',
            'vehicle',
            'students' => fn ($q) => $q->orderBy('sequence'),
            'students.student.guardian',
        ]);

        return ApiResponse::success((new SchoolTripResource($schoolTrip))->resolve());
    }

    public function start(Request $request, SchoolTrip $schoolTrip): JsonResponse
    {
        $driver = $this->driver($request);
        $this->assertOwnTrip($request, $schoolTrip);

        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $trip = $this->trips->start($schoolTrip, $driver, $this->positionFrom($validated));

        return ApiResponse::success(
            (new SchoolTripResource($trip->load(['route.school', 'vehicle', 'students.student'])))->resolve(),
        );
    }

    public function complete(Request $request, SchoolTrip $schoolTrip): JsonResponse
    {
        $this->assertOwnTrip($request, $schoolTrip);

        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $trip = $this->trips->complete($schoolTrip, $this->positionFrom($validated));

        return ApiResponse::success(
            (new SchoolTripResource($trip->load(['route.school', 'vehicle', 'students.student'])))->resolve(),
        );
    }

    // ── the two buttons ─────────────────────────────────────────────────────

    public function pickUp(Request $request, SchoolTripStudent $schoolTripStudent): JsonResponse
    {
        $this->assertOwnRow($request, $schoolTripStudent);

        $validated = $request->validate($this->positionRules());

        $row = $this->attendance->pickUp(
            $schoolTripStudent,
            $request->user(),
            $this->positionFrom($validated),
        );

        return ApiResponse::success((new SchoolTripStudentResource($row->load('student.guardian')))->resolve());
    }

    public function dropOff(Request $request, SchoolTripStudent $schoolTripStudent): JsonResponse
    {
        $this->assertOwnRow($request, $schoolTripStudent);

        $validated = $request->validate($this->positionRules());

        $row = $this->attendance->dropOff(
            $schoolTripStudent,
            $request->user(),
            $this->positionFrom($validated),
        );

        return ApiResponse::success((new SchoolTripStudentResource($row->load('student.guardian')))->resolve());
    }

    public function markAbsent(Request $request, SchoolTripStudent $schoolTripStudent): JsonResponse
    {
        $this->assertOwnRow($request, $schoolTripStudent);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:200']]);

        $row = $this->attendance->markAbsent(
            $schoolTripStudent,
            $request->user(),
            $validated['note'] ?? null,
        );

        return ApiResponse::success((new SchoolTripStudentResource($row->load('student.guardian')))->resolve());
    }

    /** Undo, because a wrong tap at a kerb in the rain is a thing that happens. */
    public function reset(Request $request, SchoolTripStudent $schoolTripStudent): JsonResponse
    {
        $this->assertOwnRow($request, $schoolTripStudent);

        $row = $this->attendance->reset($schoolTripStudent, $request->user());

        return ApiResponse::success((new SchoolTripStudentResource($row->load('student.guardian')))->resolve());
    }

    /**
     * Where the van is.
     *
     * Only accepted while a run is under way: outside one there is no family
     * entitled to the position, so there is no reason to hold it.
     */
    public function location(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:400'],
        ]);

        $trip = SchoolTrip::where('driver_id', $driver->id)
            ->live()
            ->with(['route.school', 'vehicle'])
            ->latest('started_at')
            ->first();

        if ($trip === null) {
            return ApiResponse::success(['accepted' => false, 'reason' => 'no_run_in_progress']);
        }

        $position = new Coordinate((float) $validated['lat'], (float) $validated['lng']);

        $trip->vehicle?->forceFill([
            'last_lat' => $position->lat,
            'last_lng' => $position->lng,
            'last_ping_at' => now(),
        ])->save();

        $this->live->publish($trip, $position, isset($validated['speed']) ? (float) $validated['speed'] : null);

        return ApiResponse::success([
            'accepted' => true,
            'trip_uuid' => $trip->uuid,
            'next_report_in' => 10,
        ]);
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function driver(Request $request): Driver
    {
        $driver = $request->user()->driver;

        if ($driver === null) {
            throw DomainException::make('not_a_driver', 403);
        }

        return $driver;
    }

    private function assertOwnTrip(Request $request, SchoolTrip $trip): void
    {
        abort_unless($trip->driver_id === $this->driver($request)->id, 404);
    }

    private function assertOwnRow(Request $request, SchoolTripStudent $row): void
    {
        abort_unless($row->trip?->driver_id === $this->driver($request)->id, 404);
    }

    /** @return array<string, array<int, string>> */
    private function positionRules(): array
    {
        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    private function positionFrom(array $validated): ?Coordinate
    {
        return isset($validated['lat'], $validated['lng'])
            ? new Coordinate((float) $validated['lat'], (float) $validated['lng'])
            : null;
    }
}
