<?php

namespace App\Domain\Taxi\Models;

use App\Domain\Fleet\Enums\ShiftStatus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One driver, one taxi, one mode, from clocking on to clocking off.
 *
 * `ShiftStatus` is borrowed from the bus fleet rather than duplicated: the
 * states are identical — open, closed, force-closed by the reaper — and a
 * second enum with the same cases would only need a second set of labels.
 */
class TaxiShift extends Model
{
    use BelongsToCity;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'taxi_id', 'driver_id', 'city_id', 'service_type', 'taxi_line_id',
        'pending_charter_amount', 'pending_charter_set_at',
        'started_at', 'ended_at', 'status',
        'ride_count', 'boarding_count', 'alighting_count', 'onboard_count',
        'gross_minor', 'commission_minor', 'net_minor', 'distance_meters',
        'start_lat', 'start_lng', 'end_lat', 'end_lng',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'pending_charter_set_at' => 'datetime',
            'pending_charter_amount' => 'integer',
            'ride_count' => 'integer',
            'boarding_count' => 'integer',
            'alighting_count' => 'integer',
            'onboard_count' => 'integer',
            'gross_minor' => 'integer',
            'commission_minor' => 'integer',
            'net_minor' => 'integer',
            'distance_meters' => 'integer',
            'start_lat' => 'float',
            'start_lng' => 'float',
            'end_lat' => 'float',
            'end_lng' => 'float',
            'status' => ShiftStatus::class,
            'service_type' => TaxiServiceType::class,
        ];
    }

    public function taxi(): BelongsTo
    {
        return $this->belongsTo(Taxi::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(TaxiLine::class, 'taxi_line_id');
    }

    public function rides(): HasMany
    {
        return $this->hasMany(TaxiRide::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ShiftStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::Open;
    }

    public function durationMinutes(): int
    {
        return (int) $this->started_at->diffInMinutes($this->ended_at ?? now());
    }

    /**
     * The price the driver has named, if it is still fresh.
     *
     * A charter price goes stale on purpose: an amount set twenty minutes ago
     * belongs to a hire that has already driven away, and charging the next
     * passenger who scans with it would be the worst kind of bug.
     */
    public function pendingCharterAmount(): ?int
    {
        if ($this->pending_charter_amount === null || $this->pending_charter_set_at === null) {
            return null;
        }

        $ttl = (int) config('taxi.charter.quote_ttl_seconds', 600);

        return $this->pending_charter_set_at->diffInSeconds(now()) > $ttl
            ? null
            : $this->pending_charter_amount;
    }
}
