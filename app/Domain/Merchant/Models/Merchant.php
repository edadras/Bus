<?php

namespace App\Domain\Merchant\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Enums\MerchantStatus;
use App\Domain\Merchant\Enums\MerchantType;
use App\Domain\Wallet\Enums\WalletOwnerType;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'city_id', 'owner_user_id', 'name', 'legal_name', 'code', 'type', 'status',
        'national_id', 'registration_number', 'phone', 'email', 'address', 'lat', 'lng',
        'logo_path', 'description', 'commission_bps', 'settlement_cycle',
        'iban', 'bank_account_holder', 'allows_refund', 'max_transaction_amount',
        'approved_by', 'approved_at',
    ];

    protected $hidden = ['iban', 'national_id', 'registration_number'];

    protected function casts(): array
    {
        return [
            'type' => MerchantType::class,
            'status' => MerchantStatus::class,
            'lat' => 'float',
            'lng' => 'float',
            'commission_bps' => 'integer',
            'allows_refund' => 'boolean',
            'max_transaction_amount' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(MerchantStaff::class);
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(MerchantTerminal::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MerchantTransaction::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'owner_id')
            ->where('owner_type', WalletOwnerType::Merchant->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MerchantStatus::Active->value);
    }

    public function commissionOn(int $amount): int
    {
        return (int) round($amount * $this->commission_bps / 10_000);
    }
}
