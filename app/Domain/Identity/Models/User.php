<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Merchant\Models\MerchantStaff;
use App\Domain\Network\Models\City;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Domain\Support\Models\Complaint;
use App\Domain\Wallet\Enums\WalletOwnerType;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use BelongsToCity;
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use HasUuid;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'display_name', 'mobile', 'email', 'password',
        'national_code', 'avatar_path', 'locale', 'timezone', 'status', 'city_id',
        'preferences',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'mobile_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'preferences' => 'array',
            'status' => UserStatus::class,
        ];
    }

    public function getNameAttribute(): string
    {
        $full = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $this->display_name ?: ($full !== '' ? $full : $this->maskedMobile());
    }

    /** Mobile with the middle digits hidden, for any shared or exported view. */
    public function maskedMobile(): string
    {
        $mobile = (string) $this->mobile;

        return strlen($mobile) < 7
            ? $mobile
            : substr($mobile, 0, 4).str_repeat('*', strlen($mobile) - 7).substr($mobile, -3);
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function wallet(): MorphOne
    {
        return $this->morphOne(Wallet::class, null, 'owner_type', 'owner_id')
            ->where('owner_type', WalletOwnerType::User->value);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class, 'owner_id')
            ->where('owner_type', WalletOwnerType::User->value);
    }

    public function passengerTrips(): HasMany
    {
        return $this->hasMany(PassengerTrip::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function merchantStaff(): HasMany
    {
        return $this->hasMany(MerchantStaff::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
