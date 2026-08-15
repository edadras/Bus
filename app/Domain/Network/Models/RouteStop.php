<?php

namespace App\Domain\Network\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'route_id', 'bus_stop_id', 'sequence', 'distance_from_start',
        'travel_time_from_previous', 'dwell_seconds', 'zone_id',
        'is_timepoint', 'allows_boarding', 'allows_alighting',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'distance_from_start' => 'integer',
            'travel_time_from_previous' => 'integer',
            'dwell_seconds' => 'integer',
            'is_timepoint' => 'boolean',
            'allows_boarding' => 'boolean',
            'allows_alighting' => 'boolean',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(BusRoute::class, 'route_id');
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'bus_stop_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
