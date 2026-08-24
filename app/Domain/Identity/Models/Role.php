<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public const TRANSPORT_MANAGER = 'transport_manager';

    public const FLEET_MANAGER = 'fleet_manager';

    public const DRIVER_MANAGER = 'driver_manager';

    public const FINANCE_MANAGER = 'finance_manager';

    public const SUPPORT_AGENT = 'support_agent';

    public const MERCHANT_MANAGER = 'merchant_manager';

    public const MERCHANT_STAFF = 'merchant_staff';

    public const SCHOOL_COMPANY_MANAGER = 'school_company_manager';

    public const SCHOOL_COMPANY_STAFF = 'school_company_staff';

    public const DRIVER = 'driver';

    public const PASSENGER = 'passenger';

    /** Roles that may sign in to the web admin panel. */
    public const STAFF_ROLES = [
        self::SUPER_ADMIN, self::ADMIN, self::TRANSPORT_MANAGER, self::FLEET_MANAGER,
        self::DRIVER_MANAGER, self::FINANCE_MANAGER, self::SUPPORT_AGENT, self::MERCHANT_MANAGER,
        self::SCHOOL_COMPANY_MANAGER,
    ];

    protected $fillable = ['name', 'label', 'description', 'is_system', 'level'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'level' => 'integer'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('city_id');
    }

    public function isStaffRole(): bool
    {
        return in_array($this->name, self::STAFF_ROLES, true);
    }
}
