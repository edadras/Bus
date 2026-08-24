<?php

namespace App\Domain\SchoolTransport\Models;

use App\Domain\Identity\Models\User;
use App\Domain\SchoolTransport\Enums\SchoolContractStatus;
use App\Domain\SchoolTransport\Enums\SchoolServiceDirection;
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

/**
 * One child's place on one company's service, for one school year.
 *
 * Per child and not per family on purpose. Siblings ride the same van from the
 * same door, but they are picked up separately, they are absent separately, and
 * one of them leaving mid-year must not cancel the other's place.
 */
class SchoolServiceContract extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'reference', 'school_student_id', 'guardian_user_id', 'school_company_id',
        'school_id', 'city_id', 'school_service_route_id', 'status', 'direction',
        'starts_on', 'ends_on', 'days_of_week',
        'pickup_address', 'pickup_lat', 'pickup_lng',
        'fee_amount', 'payment_cycle', 'discount_bps',
        'guardian_note', 'company_note', 'rejection_reason',
        'approved_by', 'approved_at', 'activated_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'days_of_week' => 'array',
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'fee_amount' => 'integer',
            'discount_bps' => 'integer',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'ended_at' => 'datetime',
            'status' => SchoolContractStatus::class,
            'direction' => SchoolServiceDirection::class,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(SchoolStudent::class, 'school_student_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(SchoolCompany::class, 'school_company_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(SchoolServiceRoute::class, 'school_service_route_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SchoolContractInvoice::class, 'school_service_contract_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', SchoolContractStatus::Active->value);
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /** What the family actually pays, after any agreed discount. */
    public function payableAmount(): int
    {
        $discount = intdiv($this->fee_amount * $this->discount_bps, 10_000);

        return max(0, $this->fee_amount - $discount);
    }

    /**
     * Where the van stops for this child.
     *
     * The contract's own point wins over the child's default: a family that
     * moves mid-year changes the contract, and the child record may still hold
     * the address the school has on file.
     */
    public function pickupPoint(): ?Coordinate
    {
        if ($this->pickup_lat !== null && $this->pickup_lng !== null) {
            return new Coordinate((float) $this->pickup_lat, (float) $this->pickup_lng);
        }

        return $this->student?->pickupPoint();
    }

    public function pickupAddress(): ?string
    {
        return $this->pickup_address ?: $this->student?->pickup_address;
    }

    public function runsOn(\DateTimeInterface $date): bool
    {
        if (! $this->status->isLive()) {
            return false;
        }

        if ($this->starts_on !== null && $this->starts_on->isAfter($date)) {
            return false;
        }

        if ($this->ends_on !== null && $this->ends_on->isBefore($date)) {
            return false;
        }

        $days = array_filter((array) ($this->days_of_week ?? []));

        return $days === [] || in_array((int) $date->format('N'), array_map('intval', $days), true);
    }
}
