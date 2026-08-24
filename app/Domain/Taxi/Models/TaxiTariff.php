<?php

namespace App\Domain\Taxi\Models;

use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToCity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What a city charges for a metered taxi.
 *
 * Nothing here is hard coded in the fare maths: a city that charges no waiting
 * time simply sets that rate to zero, and a night tariff is a second row with a
 * time window and a multiplier rather than a branch in the code.
 */
class TaxiTariff extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;

    protected $fillable = [
        'city_id', 'name', 'service_type', 'base_fare', 'per_km_fare',
        'per_minute_waiting_fare', 'minimum_fare', 'maximum_fare',
        'waiting_speed_kmh', 'multiplier', 'valid_from_time', 'valid_to_time',
        'priority', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'base_fare' => 'integer',
            'per_km_fare' => 'integer',
            'per_minute_waiting_fare' => 'integer',
            'minimum_fare' => 'integer',
            'maximum_fare' => 'integer',
            'waiting_speed_kmh' => 'integer',
            'multiplier' => 'float',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'service_type' => TaxiServiceType::class,
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this tariff's time window covers the given moment.
     *
     * A window that wraps midnight (22:00 to 06:00) is the normal shape of a
     * night tariff, so it is handled rather than being an unrepresentable case.
     */
    public function coversTime(\DateTimeInterface $at): bool
    {
        if ($this->valid_from_time === null || $this->valid_to_time === null) {
            return true;
        }

        $now = $at->format('H:i:s');
        $from = $this->valid_from_time;
        $to = $this->valid_to_time;

        return $from <= $to
            ? ($now >= $from && $now <= $to)
            : ($now >= $from || $now <= $to);
    }
}
