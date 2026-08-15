<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Wallet\Enums\TransactionStatus;
use App\Domain\Wallet\Enums\TransactionType;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The header of a double entry posting. Its ledger entries always balance:
 * sum(credit) - sum(debit) == 0.
 */
class WalletTransaction extends Model
{
    use BelongsToCity;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'type', 'status', 'currency', 'amount', 'idempotency_key', 'initiated_by',
        'city_id', 'subject_type', 'subject_id', 'reverses_transaction_id',
        'description', 'metadata', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'integer',
            'metadata' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Completed->value);
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount);
    }

    /** Invariant check used by tests and the ledger integrity command. */
    public function isBalanced(): bool
    {
        return $this->entries->sum(
            fn (WalletLedgerEntry $entry) => $entry->direction->signedAmount($entry->amount)
        ) === 0;
    }

    /** The signed effect of this transaction on one particular wallet. */
    public function effectOn(int $walletId): int
    {
        return $this->entries
            ->where('wallet_id', $walletId)
            ->sum(fn (WalletLedgerEntry $entry) => $entry->direction->signedAmount($entry->amount));
    }
}
