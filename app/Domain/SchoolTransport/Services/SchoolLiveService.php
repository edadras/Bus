<?php

namespace App\Domain\SchoolTransport\Services;

use App\Domain\Identity\Models\User;
use App\Domain\SchoolTransport\Enums\SchoolAttendanceStatus;
use App\Domain\SchoolTransport\Models\SchoolTrip;
use App\Domain\SchoolTransport\Models\SchoolTripStudent;
use App\Support\Exceptions\DomainException;
use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use App\Support\Live\LiveVehicleStore;

/**
 * Where the van is, and who may look.
 *
 * The privacy rule is the feature: a van's position is visible to the families
 * it is carrying, while it is carrying them, and to nobody else at any other
 * time. It is enforced in one method — `assertGuardianMayWatch` — so that the
 * two callers cannot drift apart, and it is checked against the run rather than
 * the vehicle: last week's parent must not still be watching this week's van.
 */
class SchoolLiveService
{
    private LiveVehicleStore $store;

    public function __construct()
    {
        $this->store = new LiveVehicleStore('school', (int) config('school.live.ttl_seconds', 180));
    }

    public function publish(SchoolTrip $trip, ?Coordinate $at = null, ?float $speedKmh = null): void
    {
        $position = $at ?? $trip->vehicle?->lastPosition();

        if ($position === null) {
            return;
        }

        $this->store->put($trip->city_id, $trip->id, [
            'trip_uuid' => $trip->uuid,
            'lat' => $position->lat,
            'lng' => $position->lng,
            'speed_kmh' => $speedKmh,
            'direction' => $trip->direction->value,
            'status' => $trip->status->value,
            'route_name' => $trip->route?->name,
            'school_name' => $trip->route?->school?->name,
            'vehicle_plate' => $trip->vehicle?->plate,
            'company_id' => $trip->school_company_id,
            'aboard_count' => $trip->picked_up_count,
            'expected_count' => $trip->expected_count,
        ]);
    }

    public function forget(SchoolTrip $trip): void
    {
        $this->store->forget($trip->city_id, $trip->id);
    }

    /** Every van a city's operations room may see. */
    public function forCity(int $cityId): array
    {
        return $this->store->forCity($cityId);
    }

    /**
     * What a guardian sees while their child's run is under way.
     *
     * Not a raw position: the useful answer is "how far away, how long, and how
     * many stops before mine", and everything here is scoped to that one child.
     */
    public function forGuardian(SchoolTripStudent $row, User $guardian): array
    {
        $this->assertGuardianMayWatch($row, $guardian);

        $trip = $row->trip;
        $state = $this->store->get($trip->id);

        $pickup = $row->pickupPoint();
        $position = $state === null
            ? null
            : new Coordinate((float) $state['lat'], (float) $state['lng']);

        $distance = $position !== null && $pickup !== null
            ? Distance::between($position, $pickup)
            : null;

        $stopsAhead = $trip->students()
            ->where('sequence', '<', $row->sequence)
            ->where('status', SchoolAttendanceStatus::Pending->value)
            ->count();

        return [
            'trip_uuid' => $trip->uuid,
            'status' => $trip->status->value,
            'status_label' => $trip->status->label(),
            'direction' => $trip->direction->value,
            'direction_label' => $trip->direction->label(),
            'started_at' => $trip->started_at?->toIso8601String(),

            'vehicle' => [
                'plate' => $trip->vehicle?->plate,
                'model' => $trip->vehicle?->model,
                'color' => $trip->vehicle?->color,
            ],
            'driver_name' => $trip->driver?->user?->name,
            // The driver's number, because a parent standing on a pavement with
            // a van that has not arrived needs to reach somebody.
            'driver_phone' => $trip->driver?->user?->mobile,

            'position' => $position === null ? null : [
                'lat' => $position->lat,
                'lng' => $position->lng,
                'reported_at' => $state['reported_at'] ?? null,
            ],

            'child_status' => $row->status->value,
            'child_status_label' => $row->status->label(),
            'picked_up_at' => $row->picked_up_at?->toIso8601String(),
            'dropped_off_at' => $row->dropped_off_at?->toIso8601String(),

            'stops_ahead' => $stopsAhead,
            'distance_meters' => $distance === null ? null : (int) round($distance),
            'eta' => $this->estimate($distance, $stopsAhead),
        ];
    }

    /**
     * The rule, in one place.
     *
     * Three conditions, all of them necessary: it is this guardian's child, the
     * run is actually happening, and the child has not already finished their
     * journey. The last one is what stops a parent watching a van drive on to
     * other families' houses after their own child is safely home.
     */
    public function assertGuardianMayWatch(SchoolTripStudent $row, User $guardian): void
    {
        if ($row->student?->guardian_user_id !== $guardian->id) {
            throw DomainException::make('not_your_student', 403);
        }

        $trip = $row->trip;

        if ($trip === null || ! $trip->status->allowsLiveTracking()) {
            throw DomainException::make('trip_not_live', 409, [
                'status' => $trip?->status->value,
            ]);
        }

        if ($row->status->isSettled()) {
            throw DomainException::make('child_journey_finished', 409, [
                'status' => $row->status->value,
            ]);
        }
    }

    /**
     * A deliberately conservative arrival estimate.
     *
     * Straight-line distance at an assumed urban speed, plus a minute for each
     * stop in front. It is not a routing engine and does not pretend to be one:
     * a van that arrives early is a nuisance, a van a parent missed is a child
     * standing on a pavement, so the figure leans late and says so.
     *
     * @return array<string, mixed>|null
     */
    private function estimate(?float $distanceMeters, int $stopsAhead): ?array
    {
        if ($distanceMeters === null) {
            return null;
        }

        $arrivingWithin = (int) config('school.live.arriving_within_meters', 300);

        if ($distanceMeters <= $arrivingWithin) {
            return ['minutes' => 0, 'is_arriving' => true, 'is_approximate' => true];
        }

        $speed = max(5, (int) config('school.live.eta_speed_kmh', 22));
        $travel = ($distanceMeters / 1000) / $speed * 60;
        $dwell = $stopsAhead * 1.0;

        return [
            'minutes' => max(1, (int) ceil($travel + $dwell)),
            'is_arriving' => false,
            // Always. A straight line is not a road, and saying so is the
            // difference between a useful estimate and a broken promise.
            'is_approximate' => true,
        ];
    }
}
