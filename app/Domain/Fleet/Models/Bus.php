<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Fleet\Enums\BusStatus;
use App\Domain\Network\Models\BusLine;
use App\Domain\Operations\Models\Trip;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use App\Support\Geo\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bus extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'buses';

    protected $fillable = [
        'city_id', 'operator_id', 'bus_number', 'plate', 'vin', 'model', 'manufacture_year',
        'capacity_seated', 'capacity_standing', 'has_air_conditioning', 'is_accessible',
        'status', 'current_driver_id', 'current_trip_id', 'default_line_id',
        'inspection_due_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'capacity_seated' => 'integer',
            'capacity_standing' => 'integer',
            'has_air_conditioning' => 'boolean',
            'is_accessible' => 'boolean',
            'last_ping_at' => 'datetime',
            'inspection_due_at' => 'date',
            'last_lat' => 'float',
            'last_lng' => 'float',
            'status' => BusStatus::class,
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function currentDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'current_driver_id');
    }

    public function currentTrip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'current_trip_id');
    }

    public function defaultLine(): BelongsTo
    {
        return $this->belongsTo(BusLine::class, 'default_line_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(BusDevice::class);
    }

    public function qrCodes(): HasMany
    {
        return $this->hasMany(BusQrCode::class);
    }

    public function activeQrCode(): HasOne
    {
        return $this->hasOne(BusQrCode::class)->where('is_active', true)->latestOfMany();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(BusAssignment::class);
    }

    public function totalCapacity(): int
    {
        return $this->capacity_seated + $this->capacity_standing;
    }

    public function lastPosition(): ?Coordinate
    {
        return $this->last_lat === null ? null : new Coordinate($this->last_lat, $this->last_lng);
    }

    public function scopeDeployable(Builder $query): Builder
    {
        return $query->whereIn('status', [BusStatus::Active->value, BusStatus::Idle->value]);
    }

    public function scopeOnline(Builder $query, int $withinSeconds = 180): Builder
    {
        return $query->where('last_ping_at', '>=', now()->subSeconds($withinSeconds));
    }
}
