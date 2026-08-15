<?php

namespace Database\Seeders;

use App\Domain\Fleet\Enums\BusStatus;
use App\Domain\Fleet\Enums\DriverStatus;
use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\BusAssignment;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Operator;
use App\Domain\Fleet\Services\BusQrService;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Enums\MerchantStatus;
use App\Domain\Merchant\Enums\MerchantType;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Merchant\Services\MerchantTerminalQrService;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\City;
use App\Domain\Wallet\Services\SystemAccountRegistry;
use App\Domain\Wallet\Services\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A working city: staff accounts, an operator, buses with QR codes, approved
 * drivers with assignments, merchants with terminals, and a demo passenger
 * whose wallet is topped up through the real ledger (never by writing a
 * balance directly — that would produce an unbalanced book on day one).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $city = City::where('slug', 'bandar-abbas')->firstOrFail();

        app(SystemAccountRegistry::class)->ensureAll();

        $wallets = app(WalletService::class);
        $qr = app(BusQrService::class);
        $terminals = app(MerchantTerminalQrService::class);

        $operator = Operator::updateOrCreate(
            ['city_id' => $city->id, 'code' => 'BAO'],
            ['name' => 'سازمان اتوبوس‌رانی بندرعباس', 'is_active' => true],
        );

        // ---- Staff -----------------------------------------------------
        $staff = [
            ['989120000001', 'مدیر', 'سامانه', Role::SUPER_ADMIN],
            ['989120000002', 'رضا', 'محمدی', Role::TRANSPORT_MANAGER],
            ['989120000003', 'سارا', 'کریمی', Role::FINANCE_MANAGER],
            ['989120000004', 'نیما', 'رستمی', Role::SUPPORT_AGENT],
            ['989120000005', 'مریم', 'احمدی', Role::FLEET_MANAGER],
        ];

        foreach ($staff as [$mobile, $first, $last, $role]) {
            $user = User::updateOrCreate(
                ['mobile' => $mobile],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'city_id' => $city->id,
                    'mobile_verified_at' => now(),
                    // Development credential only; production installs must
                    // rotate this immediately (see docs/deployment.md).
                    'password' => Hash::make('password'),
                ],
            );

            $user->assignRole($role, $role === Role::SUPER_ADMIN ? null : $city->id);
            $wallets->forUser($user);
        }

        // ---- Buses -----------------------------------------------------
        $lines = BusLine::where('city_id', $city->id)->get();

        $buses = collect();

        foreach (range(1, 12) as $index) {
            $bus = Bus::updateOrCreate(
                ['city_id' => $city->id, 'bus_number' => (string) (100 + $index)],
                [
                    'operator_id' => $operator->id,
                    'plate' => sprintf('%02d ه %03d ایران ۸۴', 10 + $index, 100 + $index * 7),
                    'model' => $index % 3 === 0 ? 'Scania Citywide' : 'Iran Khodro O457',
                    'manufacture_year' => 2015 + ($index % 8),
                    'capacity_seated' => 28 + ($index % 4) * 2,
                    'capacity_standing' => 18 + ($index % 3) * 4,
                    'has_air_conditioning' => true,
                    'is_accessible' => $index % 4 === 0,
                    'status' => $index > 10 ? BusStatus::Maintenance : BusStatus::Idle,
                    'default_line_id' => $lines->get(($index - 1) % max(1, $lines->count()))?->id,
                ],
            );

            if ($bus->activeQrCode === null) {
                $qr->issueFor($bus);
            }

            $buses->push($bus);
        }

        // ---- Drivers ---------------------------------------------------
        $driverNames = [
            ['علی', 'حسینی'], ['محمد', 'رضایی'], ['حسن', 'موسوی'], ['امیر', 'نجفی'],
            ['جواد', 'قاسمی'], ['سعید', 'ابراهیمی'], ['مهدی', 'شریفی'], ['یاسر', 'عباسی'],
        ];

        foreach ($driverNames as $index => [$first, $last]) {
            $mobile = '9891300000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

            $user = User::updateOrCreate(
                ['mobile' => $mobile],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'city_id' => $city->id,
                    'mobile_verified_at' => now(),
                ],
            );

            $user->assignRole(Role::DRIVER, $city->id);
            $wallets->forUser($user);

            $driver = Driver::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'city_id' => $city->id,
                    'operator_id' => $operator->id,
                    'employee_code' => 'DRV'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'national_code' => '347'.str_pad((string) (1000000 + $index * 7919), 7, '0', STR_PAD_LEFT),
                    'license_number' => 'HR'.str_pad((string) (50000 + $index * 137), 6, '0', STR_PAD_LEFT),
                    'license_class' => 'پایه یکم',
                    'license_expires_at' => now()->addYears(2)->addDays($index * 11),
                    // The last driver stays pending, so the approval gate is
                    // visible in the admin UI on a fresh install.
                    'status' => $index === count($driverNames) - 1
                        ? DriverStatus::PendingApproval
                        : DriverStatus::Active,
                    'hired_at' => now()->subMonths(6 + $index),
                    'approved_at' => $index === count($driverNames) - 1 ? null : now()->subMonths(6),
                ],
            );

            $bus = $buses->get($index);

            if ($bus !== null && $driver->status === DriverStatus::Active) {
                BusAssignment::updateOrCreate(
                    ['bus_id' => $bus->id, 'driver_id' => $driver->id, 'starts_on' => now()->subMonths(3)->toDateString()],
                    [
                        'bus_line_id' => $bus->default_line_id,
                        'is_active' => true,
                    ],
                );
            }
        }

        // ---- Passengers ------------------------------------------------
        foreach (range(1, 6) as $index) {
            $user = User::updateOrCreate(
                ['mobile' => '9891400000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT)],
                [
                    'first_name' => 'مسافر',
                    'last_name' => 'نمونه '.$index,
                    'city_id' => $city->id,
                    'mobile_verified_at' => now(),
                    'preferences' => $index === 2
                        ? ['passenger_type' => 'student']
                        : ($index === 3 ? ['passenger_type' => 'senior'] : null),
                ],
            );

            $user->assignRole(Role::PASSENGER);

            $wallet = $wallets->forUser($user);

            // Credited through the ledger, so the demo book balances exactly
            // like a production one.
            if ($wallet->balance === 0) {
                $wallets->creditTopup(
                    wallet: $wallet,
                    amount: 5_000_000,
                    idempotencyKey: 'demo-topup:'.$user->uuid,
                    metadata: ['source' => 'demo_seeder'],
                );
            }
        }

        // ---- Merchants --------------------------------------------------
        $merchants = [
            ['استخر ساحل', MerchantType::SwimmingPool, '989150000001', 27.1712, 56.2740],
            ['باشگاه بدنسازی پارس', MerchantType::Gym, '989150000002', 27.1888, 56.2610],
            ['مجموعه ورزشی خلیج فارس', MerchantType::SportsCenter, '989150000003', 27.1955, 56.2830],
            ['پارکینگ مرکزی شهرداری', MerchantType::Parking, '989150000004', 27.1845, 56.2700],
            ['فروشگاه شهروند بندر', MerchantType::Store, '989150000005', 27.1790, 56.2585],
        ];

        foreach ($merchants as $index => [$name, $type, $mobile, $lat, $lng]) {
            $owner = User::updateOrCreate(
                ['mobile' => $mobile],
                [
                    'first_name' => 'مالک',
                    'last_name' => $name,
                    'city_id' => $city->id,
                    'mobile_verified_at' => now(),
                ],
            );

            $owner->assignRole(Role::MERCHANT_MANAGER, $city->id);
            $wallets->forUser($owner);

            $merchant = Merchant::updateOrCreate(
                ['code' => 'MRC'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT)],
                [
                    'city_id' => $city->id,
                    'owner_user_id' => $owner->id,
                    'name' => $name,
                    'type' => $type,
                    'status' => MerchantStatus::Active,
                    'lat' => $lat,
                    'lng' => $lng,
                    'commission_bps' => (int) config('wallet.settlement.default_commission_bps'),
                    'approved_at' => now()->subMonths(2),
                ],
            );

            $merchant->staff()->updateOrCreate(
                ['user_id' => $owner->id],
                ['role' => 'manager', 'can_refund' => true, 'can_view_reports' => true, 'is_active' => true],
            );

            $wallets->forMerchant($merchant);

            if ($merchant->terminals()->count() === 0) {
                $terminals->issueTerminal($merchant, 'صندوق ۱', 'ورودی اصلی');
            }
        }

        $this->command?->info('  Demo data ready. Staff sign-in: 989120000001 / password');
    }
}
