<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Fleet\Enums\DriverStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Operations\Models\Trip;
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

    public function openShift(): ?DriverShift
    {
        return $this->shifts()->where('status', 'open')->latest('started_at')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DriverStatus::Active->value);
    }

    /**
     * A driver may only take a bus they are currently assigned to, and only
     * while their own record and licence are valid. Both halves matter: an
     * expired licence must stop a shift even if the assignment is still open.
     */
    public function mayOperate(Bus $bus): bool
    {
        if (! $this->status->canDrive()) {
            return false;
        }

        if ($this->license_expires_at !== null && $this->license_expires_at->isPast()) {
            return false;
        }

        if ($this->contract_ends_at !== null && $this->contract_ends_at->isPast()) {
            return false;
        }

        if ($this->city_id !== $bus->city_id) {
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
        return match (true) {
            ! $this->status->canDrive() => 'driver_not_active',
            $this->license_expires_at?->isPast() === true => 'license_expired',
            $this->contract_ends_at?->isPast() === true => 'contract_ended',
            $this->city_id !== $bus->city_id => 'city_mismatch',
            ! $this->mayOperate($bus) => 'bus_not_assigned',
            default => null,
        };
    }
}
