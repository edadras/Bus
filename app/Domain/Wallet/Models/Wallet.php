<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Wallet\Enums\WalletOwnerType;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use BelongsToCity;
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'owner_type', 'owner_id', 'account_ref', 'city_id', 'currency',
        'balance', 'pending_balance', 'status', 'daily_spend_limit',
        'frozen_at', 'frozen_reason',
    ];

    protected function casts(): array
    {
        return [
            'owner_type' => WalletOwnerType::class,
            'status' => WalletStatus::class,
            'balance' => 'integer',
            'pending_balance' => 'integer',
            'version' => 'integer',
            'daily_spend_limit' => 'integer',
            'frozen_at' => 'datetime',
        ];
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function owner()
    {
        return match ($this->owner_type) {
            WalletOwnerType::User => $this->belongsTo(User::class, 'owner_id'),
            WalletOwnerType::Merchant => $this->belongsTo(Merchant::class, 'owner_id'),
            WalletOwnerType::System => null,
        };
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('owner_type', WalletOwnerType::System->value);
    }

    public function isSystem(): bool
    {
        return $this->owner_type === WalletOwnerType::System;
    }

    /** System accounts may go negative; they are the counterparty of the float. */
    public function allowsNegativeBalance(): bool
    {
        return $this->isSystem();
    }

    public function availableBalance(): int
    {
        return $this->balance - $this->pending_balance;
    }

    public function formattedBalance(): string
    {
        return Money::format($this->balance);
    }

    public function canSpend(int $amount): bool
    {
        if (! $this->status->canDebit()) {
            return false;
        }

        if ($this->allowsNegativeBalance()) {
            return true;
        }

        $overdraft = (int) config('wallet.limits.overdraft', 0);

        return $this->availableBalance() + $overdraft >= $amount;
    }

    /** Total debited today, used to enforce the rolling daily spend limit. */
    public function spentToday(): int
    {
        return (int) $this->ledgerEntries()
            ->where('direction', 'debit')
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('amount');
    }
}
