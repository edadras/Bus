<?php

namespace App\Support\Concerns;

use App\Domain\Network\Models\City;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-city scoping. Every tenant-owned record carries city_id and can be
 * filtered with ->forCity(), which the API layer applies from the resolved
 * request city.
 */
trait BelongsToCity
{
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function scopeForCity(Builder $query, City|int|null $city): Builder
    {
        if ($city === null) {
            return $query;
        }

        return $query->where($query->qualifyColumn('city_id'), $city instanceof City ? $city->id : $city);
    }
}
