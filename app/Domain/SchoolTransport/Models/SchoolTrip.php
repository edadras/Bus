<?php

namespace App\Domain\SchoolTransport\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\SchoolTransport\Enums\SchoolServiceDirection;
use App\Domain\SchoolTransport\Enums\SchoolTripStatus;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One route's run on one morning or afternoon.
 *
 * A run, not a timetable entry: it exists for a specific date and direction,
 * which is what makes "where is the van right now" answerable and what gives
 * every check-in something concrete to hang from.
 */
class SchoolTrip extends Model
{
    use BelongsToCity;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'school_service_route_id', 'school_company_id', 'school_vehicle_id',
        'driver_id', 'city_id', 'service_date', 'direction', 'status',
        'started_at', 'ended_at', 'expected_count', 'picked_up_count',
        'dropped_off_count', 'absent_count', 'distance_meters',
        'start_lat', 'start_lng', 'end_lat', 'end_lng',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'expected_count' => 'integer',
            'picked_up_count' => 'integer',
            'dropped_off_count' => 'integer',
            'absent_count' => 'integer',
            'distance_meters' => 'integer',
            'start_lat' => 'float',
            'start_lng' => 'float',
            'end_lat' => 'float',
            'end_lng' => 'float',
            'status' => SchoolTripStatus::class,
            'direction' => SchoolServiceDirection::class,
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(SchoolServiceRoute::class, 'school_service_route_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(SchoolCompany::class, 'school_company_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(SchoolVehicle::class, 'school_vehicle_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(SchoolTripStudent::class, 'school_trip_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', SchoolTripStatus::InProgress->value);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SchoolTripStatus::Scheduled->value,
            SchoolTripStatus::InProgress->value,
        ]);
    }

    public function isLive(): bool
    {
        return $this->status === SchoolTripStatus::InProgress;
    }

    /** Children still expected to be dealt with on this run. */
    public function pendingCount(): int
    {
        return $this->students()->where('status', 'pending')->count();
    }

    public function aboardCount(): int
    {
        return $this->students()->where('status', 'picked_up')->count();
    }
}
