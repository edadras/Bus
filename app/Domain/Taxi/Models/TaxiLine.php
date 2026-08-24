<?php

namespace App\Domain\Taxi\Models;

use App\Domain\Network\Enums\NetworkProvenance;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use App\Support\Geo\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shared-taxi line: a fixed origin and destination at a published flat fare.
 */
class TaxiLine extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'city_id', 'code', 'name', 'origin_label', 'destination_label', 'color',
        'flat_fare', 'origin_lat', 'origin_lng', 'destination_lat', 'destination_lng',
        'typical_duration_minutes', 'is_active', 'provenance',
    ];

    protected function casts(): array
    {
        return [
            'flat_fare' => 'integer',
            'origin_lat' => 'float',
            'origin_lng' => 'float',
            'destination_lat' => 'float',
            'destination_lng' => 'float',
            'typical_duration_minutes' => 'integer',
            'is_active' => 'boolean',
            'provenance' => NetworkProvenance::class,
        ];
    }

    public function taxis(): HasMany
    {
        return $this->hasMany(Taxi::class, 'default_taxi_line_id');
    }

    public function rides(): HasMany
    {
        return $this->hasMany(TaxiRide::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function origin(): ?Coordinate
    {
        return $this->origin_lat === null || $this->origin_lng === null
            ? null
            : new Coordinate((float) $this->origin_lat, (float) $this->origin_lng);
    }

    public function destination(): ?Coordinate
    {
        return $this->destination_lat === null || $this->destination_lng === null
            ? null
            : new Coordinate((float) $this->destination_lat, (float) $this->destination_lng);
    }
}
