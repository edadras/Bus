<?php

namespace App\Domain\Merchant\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Wallet\Enums\TransactionStatus;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Concerns\HasUuid;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantTransaction extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'merchant_id', 'merchant_terminal_id', 'user_id', 'cashier_user_id',
        'wallet_transaction_id', 'status', 'amount', 'commission_amount', 'net_amount',
        'currency', 'reference', 'description', 'settlement_id',
        'refunded_by', 'refunded_at', 'refund_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'amount' => 'integer',
            'commission_amount' => 'integer',
            'net_amount' => 'integer',
            'refunded_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(MerchantTerminal::class, 'merchant_terminal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function scopeSettleable(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Completed->value)->whereNull('settlement_id');
    }

    public function isRefunded(): bool
    {
        return $this->refunded_at !== null;
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount);
    }
}
