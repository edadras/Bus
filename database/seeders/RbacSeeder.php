<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Roles and permissions.
 *
 * Permissions are coarse on purpose: one per capability an operator would
 * actually delegate, rather than a CRUD matrix nobody can reason about. The
 * role → permission map below is the security model in one readable place.
 */
class RbacSeeder extends Seeder
{
    /** @var array<string, array<string, string>> group => [name => description] */
    private const PERMISSIONS = [
        'dashboard' => [
            'dashboard.view' => 'مشاهده داشبورد و شاخص‌های کلیدی',
        ],
        'operations' => [
            'operations.live_map' => 'مشاهده نقشه زنده ناوگان با جزئیات راننده',
            'operations.trips.manage' => 'مدیریت سفرها و لغو سرویس',
        ],
        'fleet' => [
            'fleet.manage' => 'مدیریت اتوبوس‌ها، کد QR و تخصیص راننده',
        ],
        'taxi' => [
            'taxi.manage' => 'مدیریت تاکسی‌ها، خطوط، تعرفه و تسویه رانندگان',
        ],
        'drivers' => [
            'drivers.manage' => 'مدیریت رانندگان، مدارک و وضعیت فعالیت',
        ],
        'network' => [
            'network.manage' => 'مدیریت خطوط، ایستگاه‌ها و مسیرها',
            'network.import' => 'ورود داده شبکه از فایل',
        ],
        'finance' => [
            'finance.manage' => 'مدیریت مالی، کرایه‌ها، تسویه و برگشت تراکنش',
            'finance.audit' => 'بررسی صحت دفتر مالی',
        ],
        'merchants' => [
            'merchants.manage' => 'مدیریت پذیرندگان و صندوق‌ها',
        ],
        'support' => [
            'support.manage' => 'رسیدگی به شکایات و پاسخ به مسافران',
        ],
        'school' => [
            'school.admin' => 'تأیید شرکت‌های سرویس مدارس و نظارت شهری',
            'school.manage' => 'مدیریت ناوگان، مسیرها و قراردادهای شرکت سرویس',
        ],
        'users' => [
            'users.manage' => 'مدیریت کاربران و نقش‌ها',
            'users.pii.view' => 'مشاهده اطلاعات هویتی کامل کاربران',
        ],
        'system' => [
            'system.audit_log' => 'مشاهده گزارش عملیات حساس',
            'system.settings' => 'تغییر تنظیمات سامانه',
        ],
    ];

    /** @var array<string, array{label: string, level: int, permissions: array<int, string>}> */
    private const ROLES = [
        Role::SUPER_ADMIN => [
            'label' => 'مدیر ارشد سامانه',
            'level' => 10,
            // Super admin bypasses the check entirely; the wildcard is here so
            // the UI can display an honest permission list for the role.
            'permissions' => ['*'],
        ],
        Role::ADMIN => [
            'label' => 'مدیر',
            'level' => 9,
            'permissions' => [
                'dashboard.view', 'operations.live_map', 'operations.trips.manage',
                'fleet.manage', 'taxi.manage', 'drivers.manage', 'network.manage', 'network.import',
                'finance.manage', 'merchants.manage', 'support.manage',
                'school.admin', 'school.manage',
                'users.manage', 'system.audit_log',
            ],
        ],
        Role::TRANSPORT_MANAGER => [
            'label' => 'مدیر حمل‌ونقل',
            'level' => 7,
            'permissions' => [
                'dashboard.view', 'operations.live_map', 'operations.trips.manage',
                'network.manage', 'network.import', 'fleet.manage', 'taxi.manage',
            ],
        ],
        Role::FLEET_MANAGER => [
            'label' => 'مدیر ناوگان',
            'level' => 6,
            'permissions' => ['dashboard.view', 'operations.live_map', 'fleet.manage', 'taxi.manage'],
        ],
        Role::DRIVER_MANAGER => [
            'label' => 'مدیر رانندگان',
            'level' => 6,
            'permissions' => ['dashboard.view', 'drivers.manage', 'operations.live_map'],
        ],
        Role::FINANCE_MANAGER => [
            'label' => 'مدیر مالی',
            'level' => 7,
            'permissions' => ['dashboard.view', 'finance.manage', 'finance.audit', 'merchants.manage'],
        ],
        Role::SUPPORT_AGENT => [
            'label' => 'کارشناس پشتیبانی',
            'level' => 4,
            'permissions' => ['dashboard.view', 'support.manage'],
        ],
        Role::MERCHANT_MANAGER => [
            'label' => 'مدیر پذیرنده',
            'level' => 3,
            'permissions' => ['merchants.manage'],
        ],
        Role::SCHOOL_COMPANY_MANAGER => [
            'label' => 'مدیر شرکت سرویس مدارس',
            'level' => 3,
            // Scoped to their own company by the controllers, not by the role:
            // the permission says what they may do, and the company filter says
            // to whom.
            'permissions' => ['school.manage'],
        ],
        Role::SCHOOL_COMPANY_STAFF => [
            'label' => 'کارمند شرکت سرویس مدارس',
            'level' => 2,
            'permissions' => ['school.manage'],
        ],
        Role::MERCHANT_STAFF => [
            'label' => 'کارمند پذیرنده',
            'level' => 2,
            'permissions' => [],
        ],
        Role::DRIVER => [
            'label' => 'راننده',
            'level' => 2,
            'permissions' => [],
        ],
        Role::PASSENGER => [
            'label' => 'مسافر',
            'level' => 1,
            'permissions' => [],
        ],
    ];

    public function run(): void
    {
        $permissions = [];

        foreach (self::PERMISSIONS as $group => $items) {
            foreach ($items as $name => $description) {
                $permissions[$name] = Permission::updateOrCreate(
                    ['name' => $name],
                    ['group' => $group, 'description' => $description],
                );
            }
        }

        $permissions['*'] = Permission::updateOrCreate(
            ['name' => '*'],
            ['group' => 'system', 'description' => 'دسترسی کامل به تمام بخش‌ها'],
        );

        foreach (self::ROLES as $name => $definition) {
            $role = Role::updateOrCreate(
                ['name' => $name],
                [
                    'label' => $definition['label'],
                    'level' => $definition['level'],
                    'is_system' => true,
                ],
            );

            $role->permissions()->sync(
                collect($definition['permissions'])
                    ->map(fn (string $p) => $permissions[$p]->id ?? null)
                    ->filter()
                    ->all(),
            );
        }
    }
}
