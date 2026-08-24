<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Fleet\Enums\DriverStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Operations\Models\Trip;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiAssignment;
use App\Domain\Taxi\Models\TaxiRide;
use App\Domain\Taxi\Models\TaxiShift;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'city_id', 'operator_id', 'employee_code', 'national_code',
        'license_number', 'license_class', 'license_expires_at', 'status',
        'hired_at', 'contract_ends_at', 'approved_by', 'approved_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'license_expires_at' => 'date',
            'hired_at' => 'date',
            'contract_ends_at' => 'date',
            'approved_at' => 'datetime',
            'rating' => 'float',
            'status' => DriverStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(BusAssignment::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(DriverShift::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * The taxi side of the same person.
     *
     * A driver record is the licensed human being, not the vehicle type: the
     * same approval, the same documents and the same suspension apply whether
     * they are behind a bus or a taxi, so taxis hang off this record rather
     * than off a parallel one that would need approving all over again.
     */
    public function taxiAssignments(): HasMany
    {
        return $this->hasMany(TaxiAssignment::class);
    }

    public function taxiShifts(): HasMany
    {
        return $this->hasMany(TaxiShift::class);
    }

    public function taxiRides(): HasMany
    {
        return $this->hasMany(TaxiRide::class);
    }

    public function openTaxiShift(): ?TaxiShift
    {
        return $this->taxiShifts()->where('status', 'open')->latest('started_at')->first();
    }

    public function openShift(): ?DriverShift
    {
        return $this->shifts()->where('status', 'open')->latest('started_at')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DriverStatus::Active->value);
    }

    /**
     * Whether the person is fit to drive anything at all today.
     *
     * Separate from the vehicle question because it is the half that must not
     * drift: an expired licence has to stop a taxi shift for exactly the same
     * reason it stops a bus shift, and a second copy of these three conditions
     * is a second place for one of them to be forgotten.
     */
    public function personalBlocker(): ?string
    {
        return match (true) {
            ! $this->status->canDrive() => 'driver_not_active',
            $this->license_expires_at?->isPast() === true => 'license_expired',
            $this->contract_ends_at?->isPast() === true => 'contract_ended',
            default => null,
        };
    }

    /**
     * A driver may only take a bus they are currently assigned to, and only
     * while their own record and licence are valid. Both halves matter: an
     * expired licence must stop a shift even if the assignment is still open.
     */
    public function mayOperate(Bus $bus): bool
    {
        if ($this->personalBlocker() !== null || $this->city_id !== $bus->city_id) {
            return false;
        }

        return $this->assignments()
            ->where('bus_id', $bus->id)
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', today())
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
            ->exists();
    }

    /** Machine readable reason the driver cannot take this bus, or null. */
    public function operationBlocker(Bus $bus): ?string
    {
        return $this->personalBlocker()
            ?? match (true) {
                $this->city_id !== $bus->city_id => 'city_mismatch',
                ! $this->mayOperate($bus) => 'bus_not_assigned',
                default => null,
            };
    }

    /** The same rule for a taxi: fit to drive, same city, currently assigned. */
    public function mayOperateTaxi(Taxi $taxi): bool
    {
        if ($this->personalBlocker() !== null || $this->city_id !== $taxi->city_id) {
            return false;
        }

        return $this->taxiAssignments()
            ->where('taxi_id', $taxi->id)
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', today())
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
            ->exists();
    }

    public function taxiOperationBlocker(Taxi $taxi): ?string
    {
        return $this->personalBlocker()
            ?? match (true) {
                $this->city_id !== $taxi->city_id => 'city_mismatch',
                ! $taxi->status->isDeployable() => 'taxi_not_deployable',
                ! $this->mayOperateTaxi($taxi) => 'taxi_not_assigned',
                default => null,
            };
    }
}
