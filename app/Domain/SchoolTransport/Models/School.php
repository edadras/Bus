<?php

namespace App\Domain\SchoolTransport\Models;

use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use App\Support\Geo\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    use BelongsToCity;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'city_id', 'name', 'code', 'gender', 'level', 'address',
        'lat', 'lng', 'phone', 'starts_at', 'ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function students(): HasMany
    {
        return $this->hasMany(SchoolStudent::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(SchoolServiceRoute::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function position(): ?Coordinate
    {
        return $this->lat === null || $this->lng === null
            ? null
            : new Coordinate((float) $this->lat, (float) $this->lng);
    }
}
