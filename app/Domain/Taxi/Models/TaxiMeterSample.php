<?php

namespace App\Domain\Taxi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One GPS reading from the taxi during a metered ride, with the increment it
 * contributed.
 *
 * Storing the deltas and not merely the total is what makes a disputed fare
 * answerable: "you were billed for 4.2 km" is an assertion, and this table is
 * the evidence for it — including the readings that were thrown away and why.
 */
class TaxiMeterSample extends Model
{
    protected $fillable = [
        'taxi_ride_id', 'lat', 'lng', 'speed_kmh', 'accuracy',
        'distance_delta_meters', 'elapsed_seconds', 'is_waiting',
        'is_discarded', 'discard_reason', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'speed_kmh' => 'float',
            'accuracy' => 'float',
            'distance_delta_meters' => 'integer',
            'elapsed_seconds' => 'integer',
            'is_waiting' => 'boolean',
            'is_discarded' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(TaxiRide::class, 'taxi_ride_id');
    }
}
