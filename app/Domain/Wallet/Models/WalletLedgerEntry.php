<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Wallet\Enums\LedgerDirection;
use App\Support\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable posting line. Updates and deletes are blocked at the model level:
 * correcting a mistake means writing a reversing transaction, never editing
 * history, which is what makes the ledger auditable.
 */
class WalletLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'wallet_transaction_id', 'wallet_id', 'direction', 'amount', 'currency',
        'balance_after', 'sequence', 'description', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'direction' => LedgerDirection::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'sequence' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw DomainException::make('ledger_is_immutable', 500);
        });

        static::deleting(function (): never {
            throw DomainException::make('ledger_is_immutable', 500);
        });
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function signedAmount(): int
    {
        return $this->direction->signedAmount($this->amount);
    }
}
