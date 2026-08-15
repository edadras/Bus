<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Fleet\Enums\ShiftStatus;
use App\Domain\Operations\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverShift extends Model
{
    protected $fillable = [
        'driver_id', 'bus_id', 'started_at', 'ended_at', 'status',
        'trip_count', 'passenger_count', 'revenue_minor', 'distance_meters',
        'start_lat', 'start_lng', 'end_lat', 'end_lng',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'status' => ShiftStatus::class,
            'start_lat' => 'float',
            'start_lng' => 'float',
            'end_lat' => 'float',
            'end_lng' => 'float',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ShiftStatus::Open->value);
    }

    public function durationMinutes(): int
    {
        return (int) $this->started_at->diffInMinutes($this->ended_at ?? now());
    }
}
