<?php

namespace App\Domain\SchoolTransport\Models;

use App\Domain\Identity\Models\User;
use App\Domain\SchoolTransport\Enums\SchoolCompanyStatus;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company licensed to carry children to school.
 *
 * Approved before a parent can see it at all: a list of unvetted strangers
 * offering to drive children is not a marketplace, it is a hazard.
 */
class SchoolCompany extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'school_companies';

    protected $fillable = [
        'city_id', 'owner_user_id', 'name', 'legal_name', 'code', 'status',
        'registration_number', 'license_number', 'license_expires_at',
        'phone', 'email', 'address', 'lat', 'lng', 'description',
        'commission_bps', 'iban', 'bank_account_holder',
        'approved_by', 'approved_at', 'rejection_reason',
    ];

    // Never serialised with the model; the panel is given the last four digits
    // and a flag, which is enough to recognise an account and not to copy one.
    protected $hidden = ['iban'];

    protected function casts(): array
    {
        return [
            'license_expires_at' => 'date',
            'approved_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
            'rating' => 'float',
            'commission_bps' => 'integer',
            'contract_count' => 'integer',
            'status' => SchoolCompanyStatus::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(SchoolCompanyStaff::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(SchoolVehicle::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(SchoolServiceRoute::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(SchoolServiceContract::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', SchoolCompanyStatus::Active->value);
    }

    public function commissionBps(): int
    {
        return $this->commission_bps ?? (int) config('school.default_commission_bps', 500);
    }
}
