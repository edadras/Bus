<?php

namespace App\Domain\Network\Models;

use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Enums\RouteDirection;
use App\Domain\Operations\Models\Trip;
use App\Support\Geo\Polyline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A directed path a line is driven along. Named BusRoute (table `routes`) to
 * avoid colliding with Laravel's Route facade in every importing file.
 */
class BusRoute extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'routes';

    protected $fillable = [
        'bus_line_id', 'name', 'direction', 'origin_stop_id', 'destination_stop_id',
        'geometry', 'distance_meters', 'typical_duration_minutes',
        'is_active', 'is_default', 'provenance',
    ];

    protected function casts(): array
    {
        return [
            'geometry' => 'array',
            'distance_meters' => 'integer',
            'typical_duration_minutes' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'direction' => RouteDirection::class,
            'provenance' => NetworkProvenance::class,
        ];
    }

    private ?Polyline $polylineCache = null;

    public function line(): BelongsTo
    {
        return $this->belongsTo(BusLine::class, 'bus_line_id');
    }

    public function routeStops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'route_id')->orderBy('sequence');
    }

    public function originStop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'origin_stop_id');
    }

    public function destinationStop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'destination_stop_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Route geometry as a Polyline. Falls back to the ordered stop positions
     * when no surveyed shape has been imported, which keeps matching usable on
     * a freshly seeded network.
     */
    public function polyline(): Polyline
    {
        if ($this->polylineCache !== null) {
            return $this->polylineCache;
        }

        $raw = $this->geometry;

        if (! is_array($raw) || count($raw) < 2) {
            $raw = $this->routeStops()
                ->with('stop:id,lat,lng')
                ->get()
                ->map(fn (RouteStop $rs) => ['lat' => $rs->stop->lat, 'lng' => $rs->stop->lng])
                ->all();
        }

        return $this->polylineCache = Polyline::fromArray($raw);
    }
}
