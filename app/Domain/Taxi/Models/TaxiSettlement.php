<?php

namespace App\Domain\Taxi\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Enums\SettlementStatus;
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
 * A driver's payout request for the fares they have taken.
 *
 * Deliberately its own table rather than a second use of `settlements`: that
 * one is bound to a merchant and claims merchant transactions, and bending it
 * into a polymorphic payee would touch the one payout path already carrying
 * money. `SettlementStatus` is shared, because the state machine really is the
 * same one.
 */
class TaxiSettlement extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'driver_id', 'city_id', 'reference', 'status', 'period_start', 'period_end',
        'gross_amount', 'commission_amount', 'net_amount', 'ride_count',
        'iban', 'bank_account_holder', 'payment_reference', 'wallet_transaction_id',
        'requested_by', 'approved_by', 'approved_at', 'paid_at', 'rejection_reason',
    ];

    protected $hidden = ['iban'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_amount' => 'integer',
            'commission_amount' => 'integer',
            'net_amount' => 'integer',
            'ride_count' => 'integer',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'status' => SettlementStatus::class,
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function rides(): HasMany
    {
        return $this->hasMany(TaxiRide::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [SettlementStatus::Requested->value, SettlementStatus::Approved->value]);
    }
}
