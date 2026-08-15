<?php

namespace App\Domain\Network\Models;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Operations\Models\Trip;
use App\Support\Geo\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class City extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'slug', 'name', 'name_en', 'country_code', 'province', 'timezone', 'currency', 'locale',
        'center_lat', 'center_lng', 'default_zoom',
        'bbox_min_lat', 'bbox_min_lng', 'bbox_max_lat', 'bbox_max_lng',
        'is_active', 'is_launched', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'center_lat' => 'float',
            'center_lng' => 'float',
            'bbox_min_lat' => 'float',
            'bbox_min_lng' => 'float',
            'bbox_max_lat' => 'float',
            'bbox_max_lng' => 'float',
            'default_zoom' => 'integer',
            'is_active' => 'boolean',
            'is_launched' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(BusStop::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BusLine::class);
    }

    public function buses(): HasMany
    {
        return $this->hasMany(Bus::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeLaunched(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_launched', true);
    }

    public function center(): Coordinate
    {
        return new Coordinate($this->center_lat, $this->center_lng);
    }

    /** Reject coordinates outside the declared city envelope, when one is set. */
    public function contains(Coordinate $point): bool
    {
        if ($this->bbox_min_lat === null) {
            return true;
        }

        return $point->lat >= $this->bbox_min_lat
            && $point->lat <= $this->bbox_max_lat
            && $point->lng >= $this->bbox_min_lng
            && $point->lng <= $this->bbox_max_lng;
    }
}
