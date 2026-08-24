<?php

namespace App\Domain\SchoolTransport\Services;

use App\Domain\Fleet\Models\Driver;
use App\Domain\SchoolTransport\Enums\SchoolAttendanceStatus;
use App\Domain\SchoolTransport\Enums\SchoolServiceDirection;
use App\Domain\SchoolTransport\Enums\SchoolTripStatus;
use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Domain\SchoolTransport\Models\SchoolServiceRoute;
use App\Domain\SchoolTransport\Models\SchoolTrip;
use App\Support\Exceptions\DomainException;
use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turning routes into runs.
 *
 * A run is created for one route, one date and one direction, and the database
 * holds that combination unique — which is what stops a second tap on Start
 * producing a parallel run with half the children on it.
 *
 * The manifest is built when the run is created, not when the driver opens it:
 * a child added to a route at nine in the morning belongs to tomorrow's run,
 * and a manifest that changed under a driver mid-journey would be worse than
 * one that is a few hours stale.
 */
class SchoolTripService
{
    public function __construct(private readonly SchoolLiveService $live) {}

    /**
     * Create the runs a route owes for a date.
     *
     * @return array<int, SchoolTrip>
     */
    public function scheduleFor(SchoolServiceRoute $route, ?CarbonInterface $date = null): array
    {
        $date ??= today();

        // The collection order is distance from the school, so the school is
        // read for every run built. Loaded here rather than trusted to each
        // caller — the panel's manual button and the 02:00 job must not differ
        // in what they can safely pass in.
        $route->loadMissing('school');

        if (! $route->runsOn($date)) {
            return [];
        }

        $directions = match ($route->shift) {
            'morning' => [SchoolServiceDirection::ToSchool],
            'afternoon' => [SchoolServiceDirection::FromSchool],
            default => [SchoolServiceDirection::ToSchool, SchoolServiceDirection::FromSchool],
        };

        $trips = [];

        foreach ($directions as $direction) {
            $trips[] = $this->ensureRun($route, $date, $direction);
        }

        return array_values(array_filter($trips));
    }

    /** Today's runs for one driver, in the order they happen. */
    public function forDriver(Driver $driver, ?CarbonInterface $date = null): Collection
    {
        $date ??= today();

        return SchoolTrip::query()
            ->where('driver_id', $driver->id)
            ->whereDate('service_date', $date->toDateString())
            ->with(['route.school', 'vehicle', 'students.student'])
            ->orderByRaw("CASE direction WHEN 'to_school' THEN 0 ELSE 1 END")
            ->get();
    }

    /**
     * Start the run.
     *
     * The readiness check is not ceremony: a van with lapsed insurance or a
     * driver whose licence expired last week must not be carrying children,
     * and the moment to find that out is before they set off.
     */
    public function start(SchoolTrip $trip, Driver $driver, ?Coordinate $at = null): SchoolTrip
    {
        if ($trip->driver_id !== $driver->id) {
            throw DomainException::make('not_your_trip', 403);
        }

        if ($trip->status === SchoolTripStatus::InProgress) {
            return $trip;
        }

        if ($trip->status !== SchoolTripStatus::Scheduled) {
            throw DomainException::make('trip_not_startable', 422, ['status' => $trip->status->value]);
        }

        $blocker = $trip->route?->readinessBlocker();

        if ($blocker !== null) {
            throw DomainException::make($blocker, 422);
        }

        $open = SchoolTrip::where('driver_id', $driver->id)->live()->where('id', '!=', $trip->id)->first();

        if ($open !== null) {
            throw DomainException::make('trip_already_in_progress', 409, ['trip_uuid' => $open->uuid]);
        }

        $trip->forceFill([
            'status' => SchoolTripStatus::InProgress,
            'started_at' => now(),
            'start_lat' => $at?->lat,
            'start_lng' => $at?->lng,
        ])->save();

        $this->live->publish($trip->fresh(['route', 'vehicle']), $at);

        return $trip->fresh();
    }

    /**
     * Finish the run.
     *
     * Anyone still pending is recorded as a no-show rather than left pending
     * forever: at the end of a journey every child has either travelled or not,
     * and a row that says neither is the one a parent will ask about.
     */
    public function complete(SchoolTrip $trip, ?Coordinate $at = null): SchoolTrip
    {
        if (! $trip->status->isOpen()) {
            return $trip;
        }

        return DB::transaction(function () use ($trip, $at): SchoolTrip {
            $trip->students()
                ->where('status', SchoolAttendanceStatus::Pending->value)
                ->update(['status' => SchoolAttendanceStatus::NoShow->value]);

            // A child still marked aboard at the end of a run is a data
            // problem, not a child left in a van — but it has to be visible.
            $stillAboard = $trip->students()
                ->where('status', SchoolAttendanceStatus::PickedUp->value)
                ->count();

            $trip->forceFill([
                'status' => SchoolTripStatus::Completed,
                'ended_at' => now(),
                'end_lat' => $at?->lat,
                'end_lng' => $at?->lng,
                'absent_count' => $trip->students()
                    ->whereIn('status', [
                        SchoolAttendanceStatus::Absent->value,
                        SchoolAttendanceStatus::NoShow->value,
                    ])->count(),
                'dropped_off_count' => $trip->students()
                    ->where('status', SchoolAttendanceStatus::DroppedOff->value)
                    ->count(),
            ])->save();

            if ($stillAboard > 0) {
                logger()->warning('School run completed with children still marked aboard', [
                    'trip_uuid' => $trip->uuid,
                    'count' => $stillAboard,
                ]);
            }

            $this->live->forget($trip);

            return $trip->fresh();
        });
    }

    public function cancel(SchoolTrip $trip, string $reason): SchoolTrip
    {
        if ($trip->status === SchoolTripStatus::Completed) {
            throw DomainException::make('trip_already_completed', 422);
        }

        $trip->forceFill(['status' => SchoolTripStatus::Cancelled, 'ended_at' => now()])->save();
        $this->live->forget($trip);

        return $trip->fresh();
    }

    /** Force-close runs a driver left open. */
    public function closeAbandoned(): int
    {
        $cutoff = now()->subHours((int) config('school.trips.auto_close_after_hours', 6));

        $stale = SchoolTrip::live()->where('started_at', '<', $cutoff)->get();

        foreach ($stale as $trip) {
            $this->complete($trip);
        }

        return $stale->count();
    }

    /**
     * Build or refresh one run and its manifest.
     *
     * The pickup order is by distance from the school, furthest first on the
     * way in and nearest first on the way home — a rough but honest default
     * that a company can override by editing sequences, and far better than an
     * arbitrary one that makes the driver work out the order themselves.
     */
    private function ensureRun(
        SchoolServiceRoute $route,
        CarbonInterface $date,
        SchoolServiceDirection $direction,
    ): ?SchoolTrip {
        $contracts = $route->contracts()
            ->with('student')
            ->where('status', 'active')
            ->get()
            ->filter(fn (SchoolServiceContract $contract) => $contract->runsOn($date)
                && in_array($direction, $contract->direction->runs(), true));

        if ($contracts->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($route, $date, $direction, $contracts): SchoolTrip {
            $trip = SchoolTrip::firstOrCreate(
                [
                    'school_service_route_id' => $route->id,
                    'service_date' => $date->toDateString(),
                    'direction' => $direction->value,
                ],
                [
                    'school_company_id' => $route->school_company_id,
                    'school_vehicle_id' => $route->school_vehicle_id,
                    'driver_id' => $route->driver_id,
                    'city_id' => $route->city_id,
                    'status' => SchoolTripStatus::Scheduled,
                ],
            );

            if (! $trip->status->isOpen()) {
                return $trip;
            }

            $ordered = $this->orderContracts($contracts, $route, $direction);

            foreach ($ordered as $index => $contract) {
                $trip->students()->updateOrCreate(
                    ['school_student_id' => $contract->school_student_id],
                    [
                        'school_service_contract_id' => $contract->id,
                        'sequence' => $index + 1,
                        'pickup_address' => $contract->pickupAddress(),
                        'pickup_lat' => $contract->pickupPoint()?->lat,
                        'pickup_lng' => $contract->pickupPoint()?->lng,
                    ],
                );
            }

            $trip->forceFill(['expected_count' => count($ordered)])->save();

            // The fresh copy would forget it was just created, and the callers
            // report "N runs created" — a number an operator reads. Touched and
            // built are different claims.
            return tap($trip->fresh(), function (SchoolTrip $fresh) use ($trip): void {
                $fresh->wasRecentlyCreated = $trip->wasRecentlyCreated;
            });
        });
    }

    /** @return array<int, SchoolServiceContract> */
    private function orderContracts(
        Collection $contracts,
        SchoolServiceRoute $route,
        SchoolServiceDirection $direction,
    ): array {
        $school = $route->school?->position();

        if ($school === null) {
            return $contracts->values()->all();
        }

        $sorted = $contracts->sortBy(function (SchoolServiceContract $contract) use ($school) {
            $point = $contract->pickupPoint();

            return $point === null ? PHP_INT_MAX : Distance::between($school, $point);
        });

        return $direction->collectsFromHomes()
            ? $sorted->reverse()->values()->all()
            : $sorted->values()->all();
    }
}
