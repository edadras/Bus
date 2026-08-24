<?php

namespace App\Domain\Taxi\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which driver may open a shift on which taxi. Without one, no shift starts —
 * the same rule the bus fleet runs on, for the same reason: a car is a licensed
 * asset and who is driving it has to be a recorded decision.
 */
class TaxiAssignment extends Model
{
    protected $fillable = ['taxi_id', 'driver_id', 'starts_on', 'ends_on', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function taxi(): BelongsTo
    {
        return $this->belongsTo(Taxi::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** In force today, which is not the same thing as merely being active. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereDate('starts_on', '<=', today())
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()));
    }

    public function isCurrent(): bool
    {
        return $this->is_active
            && $this->starts_on !== null
            && ! $this->starts_on->isAfter(today())
            && ($this->ends_on === null || ! $this->ends_on->isBefore(today()));
    }
}
