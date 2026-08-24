<?php

namespace App\Domain\Taxi\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Taxi\Enums\TaxiRideStatus;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One passenger's taxi ride, in whichever of the three modes was running.
 */
class TaxiRide extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'user_id', 'taxi_id', 'driver_id', 'taxi_shift_id', 'city_id',
        'taxi_line_id', 'taxi_tariff_id', 'service_type', 'status',
        'fare_amount', 'commission_amount', 'quoted_amount', 'fare_breakdown',
        'outstanding_amount', 'wallet_transaction_id', 'taxi_settlement_id',
        'distance_meters', 'waiting_seconds', 'duration_seconds',
        'start_lat', 'start_lng', 'end_lat', 'end_lng',
        'qr_public_id', 'token_nonce', 'device_fingerprint', 'client_ip',
        'started_at', 'ended_at', 'ended_by',
    ];

    protected function casts(): array
    {
        return [
            'fare_amount' => 'integer',
            'commission_amount' => 'integer',
            'quoted_amount' => 'integer',
            'outstanding_amount' => 'integer',
            'fare_breakdown' => 'array',
            'distance_meters' => 'integer',
            'waiting_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'start_lat' => 'float',
            'start_lng' => 'float',
            'end_lat' => 'float',
            'end_lng' => 'float',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'status' => TaxiRideStatus::class,
            'service_type' => TaxiServiceType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taxi(): BelongsTo
    {
        return $this->belongsTo(Taxi::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(TaxiShift::class, 'taxi_shift_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(TaxiLine::class, 'taxi_line_id');
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(TaxiTariff::class, 'taxi_tariff_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(TaxiSettlement::class, 'taxi_settlement_id');
    }

    public function meterSamples(): HasMany
    {
        return $this->hasMany(TaxiMeterSample::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', TaxiRideStatus::Active->value);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where('status', TaxiRideStatus::Unpaid->value);
    }

    /**
     * Rides where money actually moved.
     *
     * Not "completed": a line passenger pays on boarding and may sit in the car
     * for another twenty minutes, and their fare is revenue the moment it is
     * taken. `fare_amount` is only ever written by the settlement of a real
     * posting, so this is the honest filter for every report and every payout.
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('fare_amount', '>', 0);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
