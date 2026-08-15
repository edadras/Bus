<?php

namespace App\Domain\Ridership\Models;

use App\Domain\Identity\Models\User;
use App\Support\Geo\Coordinate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opt-in passenger telemetry, retained only while a ride is open plus a short
 * grace period. Never exposed to other passengers or to unprivileged staff.
 */
class PassengerLocationPing extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'passenger_trip_id', 'user_id', 'lat', 'lng',
        'accuracy_meters', 'distance_to_bus_meters', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'accuracy_meters' => 'float',
            'distance_to_bus_meters' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    public function passengerTrip(): BelongsTo
    {
        return $this->belongsTo(PassengerTrip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coordinate(): Coordinate
    {
        return new Coordinate($this->lat, $this->lng);
    }
}
