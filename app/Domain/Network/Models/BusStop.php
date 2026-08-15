<?php

namespace App\Domain\Network\Models;

use App\Domain\Network\Enums\NetworkProvenance;
use App\Support\Concerns\BelongsToCity;
use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusStop extends Model
{
    use BelongsToCity;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'city_id', 'zone_id', 'code', 'name', 'name_en', 'lat', 'lng',
        'address', 'description', 'image_path', 'geofence_radius',
        'has_shelter', 'is_accessible', 'is_terminal', 'is_active',
        'provenance', 'source_ref',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'geofence_radius' => 'integer',
            'has_shelter' => 'boolean',
            'is_accessible' => 'boolean',
            'is_terminal' => 'boolean',
            'is_active' => 'boolean',
            'provenance' => NetworkProvenance::class,
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function routeStops(): HasMany
    {
        return $this->hasMany(RouteStop::class);
    }

    public function routes(): BelongsToMany
    {
        return $this->belongsToMany(BusRoute::class, 'route_stops', 'bus_stop_id', 'route_id')
            ->withPivot(['sequence', 'distance_from_start', 'allows_boarding', 'allows_alighting'])
            ->withTimestamps();
    }

    /** Distinct lines whose active routes call at this stop. */
    public function lines()
    {
        return BusLine::query()
            ->whereHas('routes.routeStops', fn (Builder $q) => $q->where('bus_stop_id', $this->id))
            ->where('is_active', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Bounding-box prefilter followed by an exact haversine sort. The box lets
     * the composite index do the heavy lifting; without it this is a full scan.
     */
    public function scopeNear(Builder $query, Coordinate $center, int $radiusMeters = 800): Builder
    {
        $box = Distance::boundingBox($center, $radiusMeters);

        return $query
            ->whereBetween('lat', [$box['min_lat'], $box['max_lat']])
            ->whereBetween('lng', [$box['min_lng'], $box['max_lng']]);
    }

    public function coordinate(): Coordinate
    {
        return new Coordinate($this->lat, $this->lng);
    }

    public function distanceTo(Coordinate $point): float
    {
        return Distance::between($this->coordinate(), $point);
    }
}
