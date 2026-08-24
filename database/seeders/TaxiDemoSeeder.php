<?php

namespace Database\Seeders;

use App\Domain\Fleet\Enums\DriverStatus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Operator;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Models\City;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Enums\TaxiStatus;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiAssignment;
use App\Domain\Taxi\Models\TaxiLine;
use App\Domain\Taxi\Models\TaxiTariff;
use App\Domain\Taxi\Services\TaxiQrService;
use App\Domain\Wallet\Services\WalletService;
use Illuminate\Database\Seeder;

/**
 * A working taxi fleet: lines, tariffs for all three products, cars with their
 * QR codes issued, and drivers assigned to them.
 *
 * The lines carry `provenance = sample` for exactly the same reason the bus
 * network does — these are illustrative routes, and nothing on any screen may
 * present them as the published network.
 */
class TaxiDemoSeeder extends Seeder
{
    public function run(): void
    {
        $city = City::where('slug', 'bandar-abbas')->firstOrFail();

        $wallets = app(WalletService::class);
        $qr = app(TaxiQrService::class);

        $operator = Operator::updateOrCreate(
            ['city_id' => $city->id, 'code' => 'BTX'],
            ['name' => 'سازمان تاکسی‌رانی بندرعباس', 'is_active' => true],
        );

        // ---- Lines -----------------------------------------------------
        $lines = collect([
            ['T1', 'میدان ۱۷ شهریور — گلشهر', 'میدان ۱۷ شهریور', 'گلشهر', 150_000, 18],
            ['T2', 'ترمینال — بازار ماهی‌فروشان', 'ترمینال بندرعباس', 'بازار ماهی‌فروشان', 120_000, 14],
            ['T3', 'دانشگاه هرمزگان — مرکز شهر', 'دانشگاه هرمزگان', 'مرکز شهر', 200_000, 25],
            ['T4', 'ساحل — سورو', 'بلوار ساحلی', 'سورو', 100_000, 12],
        ])->map(fn (array $row) => TaxiLine::updateOrCreate(
            ['city_id' => $city->id, 'code' => $row[0]],
            [
                'name' => $row[1],
                'origin_label' => $row[2],
                'destination_label' => $row[3],
                'flat_fare' => $row[4],
                'typical_duration_minutes' => $row[5],
                'color' => '#12b76a',
                'is_active' => true,
                'provenance' => NetworkProvenance::Sample,
            ],
        ));

        // ---- Tariffs ---------------------------------------------------
        // Two windows on the meter, so the "highest priority valid now" rule is
        // visible on a fresh install rather than only in a test.
        $tariffs = [
            ['تعرفه تاکسی‌متر روز', TaxiServiceType::Meter, [
                'base_fare' => 120_000,
                'per_km_fare' => 45_000,
                'per_minute_waiting_fare' => 18_000,
                'minimum_fare' => 150_000,
                'maximum_fare' => 20_000_000,
                'waiting_speed_kmh' => 5,
                'multiplier' => 1.0,
                'valid_from_time' => '06:00',
                'valid_to_time' => '22:00',
                'priority' => 10,
            ]],
            ['تعرفه تاکسی‌متر شب', TaxiServiceType::Meter, [
                'base_fare' => 150_000,
                'per_km_fare' => 55_000,
                'per_minute_waiting_fare' => 20_000,
                'minimum_fare' => 200_000,
                'maximum_fare' => 20_000_000,
                'waiting_speed_kmh' => 5,
                'multiplier' => 1.0,
                'valid_from_time' => '22:00',
                'valid_to_time' => '06:00',
                'priority' => 20,
            ]],
            ['تعرفه دربست', TaxiServiceType::Charter, [
                'base_fare' => 300_000,
                'minimum_fare' => 300_000,
                'maximum_fare' => 30_000_000,
                'multiplier' => 1.0,
                'priority' => 10,
            ]],
        ];

        foreach ($tariffs as [$name, $type, $attributes]) {
            TaxiTariff::updateOrCreate(
                ['city_id' => $city->id, 'name' => $name],
                $attributes + ['service_type' => $type, 'is_active' => true],
            );
        }

        // ---- Cars ------------------------------------------------------
        $cars = collect();

        foreach (range(1, 10) as $index) {
            // Every car may run a meter; only some are licensed for a line or
            // a charter, so the mode picker in the driver app is not identical
            // on every vehicle.
            $modes = match ($index % 3) {
                0 => [TaxiServiceType::Meter->value, TaxiServiceType::Charter->value],
                1 => [TaxiServiceType::Line->value, TaxiServiceType::Meter->value],
                default => TaxiServiceType::values(),
            };

            $taxi = Taxi::updateOrCreate(
                ['city_id' => $city->id, 'taxi_number' => (string) (2000 + $index)],
                [
                    'operator_id' => $operator->id,
                    'plate' => sprintf('%02d ت %03d ایران ۸۴', 20 + $index, 200 + $index * 13),
                    'model' => $index % 2 === 0 ? 'Peugeot Pars' : 'Samand LX',
                    'color' => 'زرد',
                    'manufacture_year' => 2016 + ($index % 7),
                    'capacity' => 4,
                    'has_air_conditioning' => true,
                    'is_accessible' => $index % 5 === 0,
                    'status' => $index > 9 ? TaxiStatus::Maintenance : TaxiStatus::Idle,
                    'allowed_modes' => $modes,
                    'default_taxi_line_id' => in_array(TaxiServiceType::Line->value, $modes, true)
                        ? $lines->get(($index - 1) % $lines->count())?->id
                        : null,
                    'commission_bps' => (int) config('taxi.default_commission_bps'),
                    'inspection_due_at' => now()->addMonths(6 + $index),
                ],
            );

            // A car with no code cannot take a fare, and leaving that to a
            // second click is how cars reach the street mute.
            if ($taxi->activeQrCode === null) {
                $qr->issueFor($taxi);
            }

            $cars->push($taxi);
        }

        // ---- Drivers ---------------------------------------------------
        $names = [
            ['بهروز', 'دریانورد'], ['کامران', 'صالحی'], ['فرهاد', 'بندری'],
            ['ناصر', 'زارعی'], ['اسماعیل', 'جاسمی'], ['قاسم', 'دهقانی'],
        ];

        foreach ($names as $index => [$first, $last]) {
            $mobile = '9891310000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

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
                    'employee_code' => 'TXD'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'national_code' => '349'.str_pad((string) (2000000 + $index * 6421), 7, '0', STR_PAD_LEFT),
                    'license_number' => 'TX'.str_pad((string) (70000 + $index * 191), 6, '0', STR_PAD_LEFT),
                    'license_class' => 'پایه دوم',
                    'license_expires_at' => now()->addYears(2)->addDays($index * 17),
                    'status' => DriverStatus::Active,
                    'hired_at' => now()->subMonths(4 + $index),
                    'approved_at' => now()->subMonths(4),
                ],
            );

            $taxi = $cars->get($index);

            if ($taxi !== null) {
                TaxiAssignment::updateOrCreate(
                    [
                        'taxi_id' => $taxi->id,
                        'driver_id' => $driver->id,
                        'starts_on' => now()->subMonths(2)->toDateString(),
                    ],
                    ['is_active' => true],
                );
            }
        }

        $this->command?->info('  Taxi demo data ready. Driver sign-in: 989131000001');
    }
}
