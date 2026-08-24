<?php

namespace Database\Seeders;

use App\Domain\Fleet\Enums\DriverStatus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\SchoolTransport\Models\School;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Domain\SchoolTransport\Models\SchoolServiceRoute;
use App\Domain\SchoolTransport\Models\SchoolStudent;
use App\Domain\SchoolTransport\Models\SchoolVehicle;
use App\Domain\SchoolTransport\Services\SchoolCompanyService;
use App\Domain\SchoolTransport\Services\SchoolContractService;
use App\Domain\SchoolTransport\Services\SchoolTripService;
use App\Domain\Wallet\Services\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A school service that is actually running: two companies (one approved, one
 * waiting), schools, vans, routes with a crew, families with children, and
 * today's runs already built.
 *
 * The pending company is deliberate. The approval gate is the whole reason a
 * parent can trust the list they choose from, and a fresh install where every
 * company is already approved never shows it.
 */
class SchoolTransportDemoSeeder extends Seeder
{
    public function run(): void
    {
        $city = City::where('slug', 'bandar-abbas')->firstOrFail();

        $wallets = app(WalletService::class);
        $companies = app(SchoolCompanyService::class);
        $contracts = app(SchoolContractService::class);

        $admin = User::where('mobile', '989120000001')->firstOrFail();

        // ---- Schools ---------------------------------------------------
        $schools = collect([
            ['دبستان شهید بهشتی', 'mixed', 'primary', 'بلوار امام خمینی', 27.1801, 56.2712, '07:30', '13:00'],
            ['دبیرستان دخترانه فرزانگان', 'girls', 'high', 'خیابان طالقانی', 27.1889, 56.2648, '07:00', '13:30'],
            ['متوسطه پسرانه ابن‌سینا', 'boys', 'middle', 'گلشهر شمالی', 27.1954, 56.2801, '07:15', '13:15'],
        ])->map(fn (array $row) => School::updateOrCreate(
            ['city_id' => $city->id, 'name' => $row[0]],
            [
                'gender' => $row[1],
                'level' => $row[2],
                'address' => $row[3],
                'lat' => $row[4],
                'lng' => $row[5],
                'starts_at' => $row[6],
                'ends_at' => $row[7],
                'is_active' => true,
            ],
        ));

        // ---- Companies -------------------------------------------------
        $approved = $this->company(
            $city->id,
            'شرکت سرویس ایمن‌سفر هرمزگان',
            '989160000001',
            'SCH-1401-0042',
            $wallets,
            $companies,
        );

        $companies->approve($approved, $admin);
        $approved->refresh();

        // Left pending on purpose: the approval queue in the admin panel is
        // empty on a fresh install otherwise.
        $this->company(
            $city->id,
            'شرکت سرویس راه روشن',
            '989160000002',
            'SCH-1402-0117',
            $wallets,
            $companies,
        );

        // ---- Vans and drivers ------------------------------------------
        $vans = collect();

        foreach (range(1, 3) as $index) {
            $vans->push(SchoolVehicle::updateOrCreate(
                [
                    'school_company_id' => $approved->id,
                    'plate' => sprintf('%02d و %03d ایران ۸۴', 30 + $index, 300 + $index * 19),
                ],
                [
                    'city_id' => $city->id,
                    'model' => $index === 3 ? 'Hyundai H350' : 'Iran Khodro Vanet',
                    'color' => 'سفید',
                    'manufacture_year' => 2018 + $index,
                    'capacity' => 14 + $index,
                    'status' => 'active',
                    'has_supervisor' => $index !== 2,
                    'has_seatbelts' => true,
                    'has_air_conditioning' => true,
                    'insurance_expires_at' => now()->addMonths(8 + $index),
                    'inspection_due_at' => now()->addMonths(5 + $index),
                ],
            ));
        }

        $drivers = collect();

        foreach ([['رحیم', 'کشاورز'], ['یوسف', 'دهباشی'], ['حبیب', 'مرادی']] as $index => [$first, $last]) {
            $mobile = '9891320000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

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

            $drivers->push(Driver::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'city_id' => $city->id,
                    'employee_code' => 'SCD'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'national_code' => '351'.str_pad((string) (3000000 + $index * 5407), 7, '0', STR_PAD_LEFT),
                    'license_number' => 'SC'.str_pad((string) (80000 + $index * 233), 6, '0', STR_PAD_LEFT),
                    'license_class' => 'پایه دوم',
                    'license_expires_at' => now()->addYears(3)->addDays($index * 23),
                    'status' => DriverStatus::Active,
                    'hired_at' => now()->subMonths(10 + $index),
                    'approved_at' => now()->subMonths(10),
                ],
            ));
        }

        // ---- Routes ----------------------------------------------------
        $routes = collect();

        foreach ($schools as $index => $school) {
            $routes->push(SchoolServiceRoute::updateOrCreate(
                [
                    'school_company_id' => $approved->id,
                    'school_id' => $school->id,
                    'name' => 'مسیر '.($index + 1).' — '.$school->name,
                ],
                [
                    'city_id' => $city->id,
                    'shift' => 'both',
                    'school_vehicle_id' => $vans->get($index)?->id,
                    'driver_id' => $drivers->get($index)?->id,
                    'capacity' => $vans->get($index)?->capacity ?? 14,
                    // Saturday to Wednesday: the ordinary school week here.
                    'days_of_week' => [6, 7, 1, 2, 3],
                    'pickup_starts_at' => '06:30',
                    'dropoff_starts_at' => '13:15',
                    'is_active' => true,
                ],
            ));
        }

        // ---- Families --------------------------------------------------
        $children = [
            ['سارا', 'رضایی', 'female', 'پایه چهارم', 0, 'آسم خفیف — اسپری همراه دارد', 27.2011, 56.2666],
            ['علی', 'رضایی', 'male', 'پایه هشتم', 2, null, 27.2011, 56.2666],
            ['نگین', 'حیدری', 'female', 'پایه یازدهم', 1, null, 27.1922, 56.2588],
            ['امیرحسین', 'قنبری', 'male', 'پایه ششم', 0, 'حساسیت به بادام‌زمینی', 27.1755, 56.2801],
        ];

        foreach ($children as $index => [$first, $last, $gender, $grade, $schoolIndex, $medical, $lat, $lng]) {
            // Two of these share a guardian, because a family with two children
            // at two schools is two contracts and the panel should show that.
            $guardianMobile = $index <= 1 ? '989140000001' : '98914000000'.($index + 1);

            $guardian = User::where('mobile', $guardianMobile)->first();

            if ($guardian === null) {
                continue;
            }

            $student = SchoolStudent::updateOrCreate(
                [
                    'guardian_user_id' => $guardian->id,
                    'first_name' => $first,
                    'last_name' => $last,
                ],
                [
                    'city_id' => $city->id,
                    'school_id' => $schools->get($schoolIndex)?->id,
                    'grade' => $grade,
                    'gender' => $gender,
                    'pickup_address' => 'بندرعباس، کوچه نمونه '.($index + 1),
                    'pickup_lat' => $lat,
                    'pickup_lng' => $lng,
                    'medical_notes' => $medical,
                    'emergency_contact_name' => 'اقوام درجه یک',
                    'emergency_contact_phone' => '0917'.str_pad((string) (1000000 + $index * 4441), 7, '0', STR_PAD_LEFT),
                    'is_active' => true,
                ],
            );

            if ($student->contracts()->exists()) {
                continue;
            }

            // The full path in one go, so the panel shows contracts in every
            // state rather than a queue of identical requests.
            $contract = $contracts->request($student, $approved, $guardian, [
                'starts_on' => now()->startOfMonth()->toDateString(),
                'days_of_week' => [6, 7, 1, 2, 3],
                'pickup_address' => $student->pickup_address,
                'pickup_lat' => $student->pickup_lat,
                'pickup_lng' => $student->pickup_lng,
            ]);

            // The last one is left waiting for the company's answer.
            if ($index === count($children) - 1) {
                continue;
            }

            $contract = $contracts->accept($contract, $approved->owner, 2_500_000);

            $route = $routes->get($schoolIndex);

            if ($route !== null) {
                $contracts->assignRoute($contract, $route, $approved->owner);
            }
        }

        // ---- Today's runs ----------------------------------------------
        $trips = app(SchoolTripService::class);
        $created = 0;

        foreach ($routes as $route) {
            $created += count($trips->scheduleFor($route->fresh(), today()));
        }

        $this->command?->info("  School demo data ready ($created runs today). Company sign-in: 989160000001");
    }

    private function company(
        int $cityId,
        string $name,
        string $mobile,
        string $license,
        WalletService $wallets,
        SchoolCompanyService $companies,
    ): SchoolCompany {
        $owner = User::updateOrCreate(
            ['mobile' => $mobile],
            [
                'first_name' => 'مدیر',
                'last_name' => $name,
                'city_id' => $cityId,
                'mobile_verified_at' => now(),
                // A company manager signs into the same panel an administrator
                // does, which is a password login. Development credential only.
                'password' => Hash::make('password'),
            ],
        );

        $wallets->forUser($owner);

        $existing = SchoolCompany::where('name', $name)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $companies->register([
            'name' => $name,
            'license_number' => $license,
            'license_expires_at' => now()->addYear()->toDateString(),
            'phone' => '076'.str_pad((string) (33000000 + strlen($name) * 137), 8, '0', STR_PAD_LEFT),
            'address' => 'بندرعباس، بلوار پاسداران',
            'description' => 'سرویس ایاب و ذهاب دانش‌آموزی با راننده و مراقب.',
        ], $owner, $cityId);
    }
}
