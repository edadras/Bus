<?php

namespace App\Domain\Operations\Models;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Network\Models\BusStop;
use App\Support\Geo\Coordinate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripLocation extends Model
{
    /** High volume append-only table; Eloquent timestamps are not used. */
    public $timestamps = false;

    protected $fillable = [
        'trip_id', 'bus_id', 'driver_id', 'lat', 'lng', 'speed_kmh', 'heading',
        'accuracy_meters', 'altitude', 'route_offset_meters', 'route_deviation_meters',
        'nearest_stop_id', 'recorded_at', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'speed_kmh' => 'float',
            'heading' => 'float',
            'accuracy_meters' => 'float',
            'altitude' => 'float',
            'route_offset_meters' => 'integer',
            'route_deviation_meters' => 'float',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function nearestStop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'nearest_stop_id');
    }

    public function coordinate(): Coordinate
    {
        return new Coordinate($this->lat, $this->lng);
    }
}
