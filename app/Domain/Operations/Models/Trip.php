<?php

namespace App\Domain\Operations\Models;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\DriverShift;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use App\Domain\Operations\Enums\TripStatus;
use App\Domain\Ridership\Enums\PassengerTripStatus;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use App\Support\Geo\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use BelongsToCity;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'city_id', 'bus_id', 'driver_id', 'driver_shift_id', 'bus_line_id', 'route_id',
        'status', 'scheduled_at', 'started_at', 'ended_at',
        'origin_stop_id', 'destination_stop_id',
        'current_lat', 'current_lng', 'current_speed_kmh', 'current_heading', 'route_offset_meters',
        'current_stop_id', 'next_stop_id', 'next_stop_sequence',
        'distance_to_next_stop', 'eta_next_stop_seconds',
        'passenger_count', 'peak_passenger_count', 'boarding_count', 'revenue_minor',
        'distance_meters', 'average_speed_kmh', 'is_off_route', 'is_idle', 'last_ping_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_ping_at' => 'datetime',
            'current_lat' => 'float',
            'current_lng' => 'float',
            'current_speed_kmh' => 'float',
            'current_heading' => 'float',
            'average_speed_kmh' => 'float',
            'route_offset_meters' => 'integer',
            'distance_to_next_stop' => 'integer',
            'eta_next_stop_seconds' => 'integer',
            'passenger_count' => 'integer',
            'peak_passenger_count' => 'integer',
            'boarding_count' => 'integer',
            'revenue_minor' => 'integer',
            'distance_meters' => 'integer',
            'is_off_route' => 'boolean',
            'is_idle' => 'boolean',
        ];
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'driver_shift_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BusLine::class, 'bus_line_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(BusRoute::class, 'route_id');
    }

    public function originStop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'origin_stop_id');
    }

    public function destinationStop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'destination_stop_id');
    }

    public function currentStop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'current_stop_id');
    }

    public function nextStop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'next_stop_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(TripLocation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TripEvent::class);
    }

    public function passengerTrips(): HasMany
    {
        return $this->hasMany(PassengerTrip::class);
    }

    public function activePassengerTrips(): HasMany
    {
        return $this->passengerTrips()->whereIn('status', [
            PassengerTripStatus::Active->value,
            PassengerTripStatus::PendingAlighting->value,
        ]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TripStatus::Active->value);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TripStatus::Starting->value,
            TripStatus::Active->value,
            TripStatus::Paused->value,
        ]);
    }

    public function position(): ?Coordinate
    {
        return $this->current_lat === null ? null : new Coordinate($this->current_lat, $this->current_lng);
    }

    /** True when telemetry has gone quiet long enough to distrust the ETA. */
    public function isStale(): bool
    {
        return $this->last_ping_at === null
            || $this->last_ping_at->lt(now()->subSeconds((int) config('transit.eta.stale_after_seconds')));
    }

    public function occupancyRatio(): float
    {
        $capacity = $this->bus?->totalCapacity() ?: 0;

        return $capacity > 0 ? round($this->passenger_count / $capacity, 3) : 0.0;
    }

    public function durationSeconds(): int
    {
        return $this->started_at === null
            ? 0
            : (int) $this->started_at->diffInSeconds($this->ended_at ?? now());
    }
}
