<?php

namespace App\Domain\SchoolTransport\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use App\Support\Geo\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolVehicle extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'school_company_id', 'city_id', 'plate', 'model', 'color',
        'manufacture_year', 'capacity', 'status', 'has_supervisor',
        'has_air_conditioning', 'has_seatbelts', 'insurance_expires_at',
        'inspection_due_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'has_supervisor' => 'boolean',
            'has_air_conditioning' => 'boolean',
            'has_seatbelts' => 'boolean',
            'insurance_expires_at' => 'date',
            'inspection_due_at' => 'date',
            'last_ping_at' => 'datetime',
            'last_lat' => 'float',
            'last_lng' => 'float',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(SchoolCompany::class, 'school_company_id');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(SchoolServiceRoute::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function lastPosition(): ?Coordinate
    {
        return $this->last_lat === null || $this->last_lng === null
            ? null
            : new Coordinate((float) $this->last_lat, (float) $this->last_lng);
    }

    /**
     * Paperwork that has to be in date for the van to be on the road.
     *
     * Surfaced as an answer rather than two dates the panel has to compare, so
     * a lapsed insurance certificate is visible rather than inferable.
     */
    public function complianceBlocker(): ?string
    {
        return match (true) {
            $this->status !== 'active' => 'vehicle_not_active',
            $this->insurance_expires_at?->isPast() === true => 'insurance_expired',
            default => null,
        };
    }
}
