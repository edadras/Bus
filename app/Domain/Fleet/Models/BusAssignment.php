<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusAssignment extends Model
{
    protected $fillable = [
        'bus_id', 'driver_id', 'bus_line_id', 'starts_on', 'ends_on', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BusLine::class, 'bus_line_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereDate('starts_on', '<=', today())
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()));
    }
}
