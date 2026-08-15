<?php

namespace App\Domain\Operations\Services;

use App\Domain\Fleet\Enums\BusStatus;
use App\Domain\Fleet\Enums\ShiftStatus;
use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\DriverShift;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Operations\Enums\TripEventType;
use App\Domain\Operations\Enums\TripStatus;
use App\Domain\Operations\Events\TripStatusChanged;
use App\Domain\Operations\Models\Trip;
use App\Domain\Ridership\Enums\AlightingSource;
use App\Domain\Ridership\Services\AlightingService;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the trip and shift lifecycle. Every state change goes through here so
 * the denormalised pointers on `buses`, the shift counters and the live map
 * can never drift apart from the trip's real status.
 */
class TripService
{
    public function __construct(
        private readonly LiveStateStore $liveState,
        private readonly TripEventRecorder $events,
        private readonly AlightingService $alighting,
    ) {}

    /**
     * Begin a shift after the driver scans the QR inside the bus.
     *
     * Authorisation is checked here rather than in the controller because the
     * same rule must hold for any caller: a driver may only operate a bus they
     * are currently assigned to, with a valid licence, in their own city.
     */
    public function startShift(Driver $driver, Bus $bus, ?float $lat = null, ?float $lng = null): DriverShift
    {
        $blocker = $driver->operationBlocker($bus);

        if ($blocker !== null) {
            throw DomainException::make($blocker, 403, [
                'driver_id' => $driver->id,
                'bus_id' => $bus->id,
            ]);
        }

        if (! $bus->status->isDeployable()) {
            throw DomainException::make('bus_not_deployable', 422, ['status' => $bus->status->value]);
        }

        return DB::transaction(function () use ($driver, $bus, $lat, $lng): DriverShift {
            // A bus may host only one open shift; otherwise two drivers could
            // both claim it and passengers would board an ambiguous trip.
            $busShift = DriverShift::where('bus_id', $bus->id)
                ->where('status', ShiftStatus::Open->value)
                ->lockForUpdate()
                ->first();

            if ($busShift !== null && $busShift->driver_id !== $driver->id) {
                throw DomainException::make('bus_already_in_service', 409);
            }

            if ($busShift !== null) {
                return $busShift;
            }

            // Equally, a driver cannot be on two buses at once.
            $openElsewhere = DriverShift::where('driver_id', $driver->id)
                ->where('status', ShiftStatus::Open->value)
                ->exists();

            if ($openElsewhere) {
                throw DomainException::make('driver_already_on_shift', 409);
            }

            $shift = DriverShift::create([
                'driver_id' => $driver->id,
                'bus_id' => $bus->id,
                'started_at' => now(),
                'status' => ShiftStatus::Open,
                'start_lat' => $lat,
                'start_lng' => $lng,
            ]);

            $bus->forceFill([
                'current_driver_id' => $driver->id,
                'status' => BusStatus::Active,
            ])->save();

            // Counters (trip_count, revenue_minor, ...) are database defaults,
            // so the in-memory instance would report them as null until reread.
            return $shift->refresh();
        });
    }

    public function endShift(DriverShift $shift, ?float $lat = null, ?float $lng = null): DriverShift
    {
        return DB::transaction(function () use ($shift, $lat, $lng): DriverShift {
            foreach ($shift->trips()->whereIn('status', [
                TripStatus::Scheduled->value,
                TripStatus::Starting->value,
                TripStatus::Active->value,
                TripStatus::Paused->value,
            ])->get() as $trip) {
                $this->complete($trip);
            }

            $shift->forceFill([
                'ended_at' => now(),
                'status' => ShiftStatus::Closed,
                'end_lat' => $lat,
                'end_lng' => $lng,
            ])->save();

            $driver = $shift->driver;
            $driver->forceFill([
                'total_shift_minutes' => $driver->total_shift_minutes + $shift->durationMinutes(),
            ])->save();

            $shift->bus?->forceFill([
                'current_driver_id' => null,
                'current_trip_id' => null,
                'status' => BusStatus::Idle,
            ])->save();

            return $shift;
        });
    }

    /** Put a bus into revenue service on a given route. */
    public function start(DriverShift $shift, BusRoute $route): Trip
    {
        $line = $route->line;

        if ($line === null || ! $line->is_active) {
            throw DomainException::make('line_not_active', 422);
        }

        if ($shift->status !== ShiftStatus::Open) {
            throw DomainException::make('shift_not_open', 422);
        }

        return DB::transaction(function () use ($shift, $route, $line): Trip {
            $existing = Trip::where('bus_id', $shift->bus_id)
                ->live()
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw DomainException::make('bus_already_on_trip', 409, ['trip_uuid' => $existing->uuid]);
            }

            $stops = $route->routeStops()->with('stop')->get();

            $trip = Trip::create([
                'city_id' => $shift->bus->city_id,
                'bus_id' => $shift->bus_id,
                'driver_id' => $shift->driver_id,
                'driver_shift_id' => $shift->id,
                'bus_line_id' => $line->id,
                'route_id' => $route->id,
                'status' => TripStatus::Active,
                'started_at' => now(),
                'origin_stop_id' => $route->origin_stop_id ?? $stops->first()?->bus_stop_id,
                'destination_stop_id' => $route->destination_stop_id ?? $stops->last()?->bus_stop_id,
                'next_stop_id' => $stops->first()?->bus_stop_id,
                'next_stop_sequence' => $stops->first()?->sequence,
                // Deliberately no last_ping_at: no position has been received
                // yet. Stamping it here would make the ingest throttle discard
                // the driver's very first report, leaving the bus off the live
                // map until the next one.
            ]);

            $shift->bus->forceFill([
                'current_trip_id' => $trip->id,
                'status' => BusStatus::Active,
            ])->save();

            $shift->increment('trip_count');

            $this->events->record($trip, TripEventType::Started, ['route_id' => $route->id]);

            TripStatusChanged::dispatch($trip->id, $trip->city_id, TripStatus::Active->value);

            return $trip;
        });
    }

    public function pause(Trip $trip): Trip
    {
        $this->assertTransition($trip, [TripStatus::Active]);

        $trip->forceFill(['status' => TripStatus::Paused])->save();
        $this->events->record($trip, TripEventType::Paused, []);
        TripStatusChanged::dispatch($trip->id, $trip->city_id, TripStatus::Paused->value);

        return $trip;
    }

    public function resume(Trip $trip): Trip
    {
        $this->assertTransition($trip, [TripStatus::Paused]);

        $trip->forceFill(['status' => TripStatus::Active])->save();
        $this->events->record($trip, TripEventType::Resumed, []);
        TripStatusChanged::dispatch($trip->id, $trip->city_id, TripStatus::Active->value);

        return $trip;
    }

    /**
     * End the trip and close out every passenger still aboard. Passengers are
     * never left with an open ride, because an open ride blocks their next
     * boarding and would keep charging them a phantom presence on the bus.
     */
    public function complete(Trip $trip): Trip
    {
        if (! $trip->status->isOpen()) {
            return $trip;
        }

        return DB::transaction(function () use ($trip): Trip {
            foreach ($trip->activePassengerTrips()->with('trip.route')->get() as $passengerTrip) {
                $this->alighting->close(
                    $passengerTrip,
                    AlightingSource::TripEnded,
                    confidence: 1.0,
                    stopId: $trip->current_stop_id ?? $trip->destination_stop_id,
                );
            }

            $duration = $trip->durationSeconds();

            $trip->forceFill([
                'status' => TripStatus::Completed,
                'ended_at' => now(),
                'passenger_count' => 0,
                'average_speed_kmh' => $duration > 0
                    ? round(($trip->distance_meters / $duration) * 3.6, 2)
                    : null,
            ])->save();

            $trip->shift?->forceFill([
                'distance_meters' => ($trip->shift->distance_meters ?? 0) + $trip->distance_meters,
            ])->save();

            $trip->driver?->increment('total_trips');

            if ($trip->bus?->current_trip_id === $trip->id) {
                $trip->bus->forceFill(['current_trip_id' => null])->save();
            }

            $this->events->record($trip, TripEventType::Completed, [
                'distance_meters' => $trip->distance_meters,
                'boarding_count' => $trip->boarding_count,
            ]);

            $this->liveState->forget($trip);

            TripStatusChanged::dispatch($trip->id, $trip->city_id, TripStatus::Completed->value);

            return $trip;
        });
    }

    public function cancel(Trip $trip, string $reason): Trip
    {
        return DB::transaction(function () use ($trip, $reason): Trip {
            foreach ($trip->activePassengerTrips()->with('trip.route')->get() as $passengerTrip) {
                $this->alighting->close($passengerTrip, AlightingSource::TripEnded, 1.0);
            }

            $trip->forceFill([
                'status' => TripStatus::Cancelled,
                'ended_at' => now(),
                'passenger_count' => 0,
            ])->save();

            if ($trip->bus?->current_trip_id === $trip->id) {
                $trip->bus->forceFill(['current_trip_id' => null])->save();
            }

            $this->events->record($trip, TripEventType::Cancelled, ['reason' => $reason]);
            $this->liveState->forget($trip);

            TripStatusChanged::dispatch($trip->id, $trip->city_id, TripStatus::Cancelled->value);

            return $trip;
        });
    }

    /** The trip a passenger scanning this bus should be attached to. */
    public function activeTripFor(Bus $bus): ?Trip
    {
        return Trip::where('bus_id', $bus->id)
            ->where('status', TripStatus::Active->value)
            ->latest('started_at')
            ->first();
    }

    /** @param array<int, TripStatus> $allowed */
    private function assertTransition(Trip $trip, array $allowed): void
    {
        if (! in_array($trip->status, $allowed, true)) {
            throw DomainException::make('invalid_trip_transition', 422, [
                'from' => $trip->status->value,
                'allowed' => array_map(fn (TripStatus $s) => $s->value, $allowed),
            ]);
        }
    }
}
