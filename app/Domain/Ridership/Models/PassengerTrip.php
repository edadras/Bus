<?php

namespace App\Domain\Ridership\Models;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use App\Domain\Operations\Models\Trip;
use App\Domain\Ridership\Enums\PassengerTripStatus;
use App\Domain\Wallet\Models\FareRule;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One passenger's ride aboard one bus trip: the fare-bearing unit. */
class PassengerTrip extends Model
{
    use BelongsToCity;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'user_id', 'trip_id', 'bus_id', 'city_id', 'bus_line_id', 'route_id', 'status',
        'boarding_stop_id', 'alighting_stop_id', 'boarded_at', 'alighted_at',
        'fare_amount', 'fare_rule_id', 'wallet_transaction_id',
        'distance_meters', 'stops_travelled', 'duration_seconds',
        'alighting_confidence', 'alighting_source',
    ];

    protected function casts(): array
    {
        return [
            'status' => PassengerTripStatus::class,
            'boarded_at' => 'datetime',
            'alighted_at' => 'datetime',
            'fare_amount' => 'integer',
            'distance_meters' => 'integer',
            'stops_travelled' => 'integer',
            'duration_seconds' => 'integer',
            'alighting_confidence' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BusLine::class, 'bus_line_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(BusRoute::class, 'route_id');
    }

    public function boardingStop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'boarding_stop_id');
    }

    public function alightingStop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'alighting_stop_id');
    }

    public function fareRule(): BelongsTo
    {
        return $this->belongsTo(FareRule::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function boarding(): HasOne
    {
        return $this->hasOne(PassengerBoarding::class);
    }

    public function alightings(): HasMany
    {
        return $this->hasMany(PassengerAlighting::class);
    }

    public function locationPings(): HasMany
    {
        return $this->hasMany(PassengerLocationPing::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PassengerTripStatus::Active->value,
            PassengerTripStatus::PendingAlighting->value,
        ]);
    }

    public function isOpen(): bool
    {
        return $this->status->occupiesSeat();
    }
}
