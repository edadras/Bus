<?php

namespace App\Domain\Network\Models;

use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Enums\RouteDirection;
use App\Domain\Operations\Models\Trip;
use App\Support\Concerns\BelongsToCity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusLine extends Model
{
    use BelongsToCity;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'city_id', 'code', 'name', 'name_en', 'color',
        'origin_label', 'destination_label',
        'typical_duration_minutes', 'headway_minutes',
        'service_start', 'service_end',
        'is_active', 'provenance', 'description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'typical_duration_minutes' => 'integer',
            'headway_minutes' => 'integer',
            'provenance' => NetworkProvenance::class,
        ];
    }

    public function routes(): HasMany
    {
        return $this->hasMany(BusRoute::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function defaultRoute(): ?BusRoute
    {
        return $this->routes()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    public function routeFor(RouteDirection $direction): ?BusRoute
    {
        return $this->routes()->where('is_active', true)->where('direction', $direction->value)->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
