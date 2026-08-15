<?php

namespace App\Domain\Merchant\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Enums\SettlementStatus;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Concerns\HasUuid;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'merchant_id', 'reference', 'status', 'period_start', 'period_end',
        'transaction_count', 'gross_amount', 'commission_amount', 'refund_amount',
        'net_amount', 'currency', 'wallet_transaction_id',
        'requested_by', 'requested_at', 'approved_by', 'approved_at',
        'paid_at', 'payment_reference', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => SettlementStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'transaction_count' => 'integer',
            'gross_amount' => 'integer',
            'commission_amount' => 'integer',
            'refund_amount' => 'integer',
            'net_amount' => 'integer',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MerchantTransaction::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function formattedNet(): string
    {
        return Money::format($this->net_amount);
    }
}
