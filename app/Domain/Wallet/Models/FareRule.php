<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\Zone;
use App\Support\Concerns\BelongsToCity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single priced condition. Fares are never hard coded: the engine collects
 * every rule whose conditions match and takes the highest priority one.
 */
class FareRule extends Model
{
    use BelongsToCity;
    use HasFactory;

    protected $fillable = [
        'city_id', 'name', 'code', 'context', 'bus_line_id', 'from_zone_id', 'to_zone_id',
        'passenger_type', 'valid_from_time', 'valid_to_time', 'valid_days',
        'valid_from_date', 'valid_to_date', 'base_fare', 'per_km_fare',
        'min_fare', 'max_fare', 'multiplier', 'priority', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'valid_days' => 'array',
            'valid_from_date' => 'date',
            'valid_to_date' => 'date',
            'base_fare' => 'integer',
            'per_km_fare' => 'integer',
            'min_fare' => 'integer',
            'max_fare' => 'integer',
            'multiplier' => 'float',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BusLine::class, 'bus_line_id');
    }

    public function fromZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'from_zone_id');
    }

    public function toZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'to_zone_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Date/time predicates that the SQL layer cannot express cleanly. */
    public function appliesAt(CarbonInterface $at): bool
    {
        if ($this->valid_from_date !== null && $at->lt($this->valid_from_date->startOfDay())) {
            return false;
        }

        if ($this->valid_to_date !== null && $at->gt($this->valid_to_date->endOfDay())) {
            return false;
        }

        if (is_array($this->valid_days) && $this->valid_days !== []
            && ! in_array($at->isoWeekday(), $this->valid_days, true)) {
            return false;
        }

        if ($this->valid_from_time !== null || $this->valid_to_time !== null) {
            $now = $at->format('H:i:s');
            $from = $this->valid_from_time ?? '00:00:00';
            $to = $this->valid_to_time ?? '23:59:59';

            // A window may wrap past midnight, e.g. a 22:00-05:00 night fare.
            return $from <= $to
                ? ($now >= $from && $now <= $to)
                : ($now >= $from || $now <= $to);
        }

        return true;
    }

    /** Specificity score; a tie on priority is broken by the tighter rule. */
    public function specificity(): int
    {
        return ($this->bus_line_id !== null ? 8 : 0)
            + ($this->passenger_type !== null ? 4 : 0)
            + ($this->from_zone_id !== null ? 2 : 0)
            + ($this->to_zone_id !== null ? 1 : 0);
    }
}
