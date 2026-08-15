<?php

namespace App\Domain\Operations\Models;

use App\Domain\Network\Models\BusStop;
use App\Domain\Operations\Enums\TripEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripEvent extends Model
{
    protected $fillable = ['trip_id', 'type', 'bus_stop_id', 'lat', 'lng', 'payload', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'type' => TripEventType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'bus_stop_id');
    }
}
