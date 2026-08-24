<?php

namespace App\Domain\SchoolTransport\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A van, a driver, a school and the children they collect.
 *
 * The route is the company's unit of planning: contracts are placed on it, and
 * a run is what one route does on one morning.
 */
class SchoolServiceRoute extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'school_company_id', 'school_id', 'city_id', 'name', 'code', 'shift',
        'school_vehicle_id', 'driver_id', 'supervisor_user_id', 'capacity',
        'days_of_week', 'pickup_starts_at', 'dropoff_starts_at', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'days_of_week' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(SchoolCompany::class, 'school_company_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(SchoolVehicle::class, 'school_vehicle_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(SchoolServiceContract::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(SchoolTrip::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Contracts currently riding on this route. */
    public function liveContracts()
    {
        return $this->contracts()->where('status', 'active');
    }

    public function seatsTaken(): int
    {
        return $this->liveContracts()->count();
    }

    public function seatsFree(): int
    {
        return max(0, ($this->capacity ?? 0) - $this->seatsTaken());
    }

    /**
     * Does this route run on a given day?
     *
     * An empty list means every school day rather than none: a route whose
     * days were never filled in is the common case, and reading that as "runs
     * on no day" would silently stop a van that is out there every morning.
     */
    public function runsOn(\DateTimeInterface $date): bool
    {
        $days = array_filter((array) ($this->days_of_week ?? []));

        if ($days === []) {
            return true;
        }

        return in_array((int) $date->format('N'), array_map('intval', $days), true);
    }

    /** What the van is missing before it can go out. */
    public function readinessBlocker(): ?string
    {
        return match (true) {
            ! $this->is_active => 'route_inactive',
            $this->school_vehicle_id === null => 'no_vehicle_assigned',
            $this->driver_id === null => 'no_driver_assigned',
            $this->vehicle?->complianceBlocker() !== null => $this->vehicle->complianceBlocker(),
            $this->driver?->personalBlocker() !== null => $this->driver->personalBlocker(),
            default => null,
        };
    }
}
