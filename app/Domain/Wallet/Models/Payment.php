<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Support\Concerns\HasUuid;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A gateway top-up attempt. One payment maps to at most one wallet posting. */
class Payment extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'user_id', 'wallet_id', 'wallet_transaction_id', 'gateway', 'status',
        'amount', 'currency', 'gateway_reference', 'gateway_authority',
        'card_mask', 'gateway_payload', 'failure_reason', 'return_url',
        'client_ip', 'paid_at', 'expires_at',
    ];

    protected $hidden = ['gateway_payload'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'gateway_payload' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount);
    }
}
