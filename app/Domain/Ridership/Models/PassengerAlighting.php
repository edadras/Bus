<?php

namespace App\Domain\Ridership\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusStop;
use App\Domain\Operations\Models\Trip;
use App\Domain\Ridership\Enums\AlightingSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A detection that a passenger left the bus. Several may exist for one ride:
 * a low confidence detection is recorded but not acted on until corroborated.
 */
class PassengerAlighting extends Model
{
    protected $fillable = [
        'passenger_trip_id', 'trip_id', 'user_id', 'bus_stop_id', 'source',
        'confidence', 'signals', 'lat', 'lng', 'distance_to_bus_meters',
        'detected_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => AlightingSource::class,
            'confidence' => 'float',
            'signals' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'distance_to_bus_meters' => 'float',
            'detected_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function passengerTrip(): BelongsTo
    {
        return $this->belongsTo(PassengerTrip::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'bus_stop_id');
    }

    public function isConfident(): bool
    {
        return $this->confidence >= (float) config('transit.ridership.alighting_confidence_threshold');
    }
}
