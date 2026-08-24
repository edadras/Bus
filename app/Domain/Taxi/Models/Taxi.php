<?php

namespace App\Domain\Taxi\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Operator;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Enums\TaxiStatus;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use App\Support\Geo\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Taxi extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'taxis';

    protected $fillable = [
        'city_id', 'operator_id', 'taxi_number', 'plate', 'model', 'color',
        'manufacture_year', 'capacity', 'has_air_conditioning', 'is_accessible',
        'status', 'allowed_modes', 'default_taxi_line_id', 'commission_bps',
        'current_driver_id', 'current_shift_id', 'inspection_due_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'has_air_conditioning' => 'boolean',
            'is_accessible' => 'boolean',
            'allowed_modes' => 'array',
            'commission_bps' => 'integer',
            'last_ping_at' => 'datetime',
            'inspection_due_at' => 'date',
            'last_lat' => 'float',
            'last_lng' => 'float',
            'status' => TaxiStatus::class,
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function defaultLine(): BelongsTo
    {
        return $this->belongsTo(TaxiLine::class, 'default_taxi_line_id');
    }

    public function currentDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'current_driver_id');
    }

    public function qrCodes(): HasMany
    {
        return $this->hasMany(TaxiQrCode::class);
    }

    public function activeQrCode(): HasOne
    {
        return $this->hasOne(TaxiQrCode::class)->where('is_active', true)->latestOfMany();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaxiAssignment::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(TaxiShift::class);
    }

    public function rides(): HasMany
    {
        return $this->hasMany(TaxiRide::class);
    }

    public function scopeDeployable(Builder $query): Builder
    {
        return $query->whereIn('status', [TaxiStatus::Active->value, TaxiStatus::Idle->value]);
    }

    /**
     * Which of the three modes this car is licensed to run.
     *
     * An empty or missing list means all three: a plain city taxi with no
     * special licensing is the common case, and requiring every row to spell
     * that out would make the omission look like a restriction.
     *
     * @return array<int, TaxiServiceType>
     */
    public function allowedModes(): array
    {
        $stored = array_filter((array) ($this->allowed_modes ?? []));

        if ($stored === []) {
            return TaxiServiceType::cases();
        }

        return array_values(array_filter(array_map(
            static fn (string $value) => TaxiServiceType::tryFrom($value),
            $stored,
        )));
    }

    public function allowsMode(TaxiServiceType $mode): bool
    {
        return in_array($mode, $this->allowedModes(), true);
    }

    public function lastPosition(): ?Coordinate
    {
        return $this->last_lat === null || $this->last_lng === null
            ? null
            : new Coordinate((float) $this->last_lat, (float) $this->last_lng);
    }

    /** Commission in basis points, falling back to the platform default. */
    public function commissionBps(): int
    {
        return $this->commission_bps ?? (int) config('taxi.default_commission_bps', 1000);
    }
}
