<?php

namespace App\Domain\Fleet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusDevice extends Model
{
    protected $fillable = [
        'bus_id', 'type', 'identifier', 'firmware_version', 'last_seen_at', 'is_active',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }
}
