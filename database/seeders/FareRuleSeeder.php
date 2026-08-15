<?php

namespace Database\Seeders;

use App\Domain\Network\Models\City;
use App\Domain\Wallet\Models\FareRule;
use Illuminate\Database\Seeder;

/**
 * Starting fare table. Amounts are in rials (the wallet's minor unit) and are
 * a working default, not a published tariff — finance staff set the real
 * numbers in the admin panel, which is the whole point of the fare engine.
 */
class FareRuleSeeder extends Seeder
{
    public function run(): void
    {
        $city = City::where('slug', 'bandar-abbas')->first();

        if ($city === null) {
            return;
        }

        $rules = [
            [
                'code' => 'BUS-STANDARD',
                'name' => 'کرایه عادی اتوبوس',
                'passenger_type' => 'regular',
                'base_fare' => 50_000,
                'priority' => 10,
            ],
            [
                'code' => 'BUS-STUDENT',
                'name' => 'کرایه دانش‌آموزی و دانشجویی',
                'passenger_type' => 'student',
                'base_fare' => 50_000,
                'multiplier' => 0.5,
                'priority' => 20,
            ],
            [
                'code' => 'BUS-SENIOR',
                'name' => 'کرایه سالمندان',
                'passenger_type' => 'senior',
                'base_fare' => 50_000,
                'multiplier' => 0.5,
                'priority' => 20,
            ],
            [
                'code' => 'BUS-DISABLED',
                'name' => 'کرایه جانبازان و معلولان',
                'passenger_type' => 'disabled',
                'base_fare' => 0,
                'priority' => 30,
            ],
            [
                'code' => 'BUS-CHILD',
                'name' => 'کرایه کودکان',
                'passenger_type' => 'child',
                'base_fare' => 0,
                'priority' => 30,
            ],
            [
                // Off-peak evening discount; wraps past midnight, which the
                // rule's time comparison handles explicitly.
                'code' => 'BUS-NIGHT',
                'name' => 'کرایه شبانه',
                'passenger_type' => 'regular',
                'base_fare' => 50_000,
                'multiplier' => 0.8,
                'valid_from_time' => '22:00:00',
                'valid_to_time' => '05:30:00',
                'priority' => 40,
            ],
        ];

        foreach ($rules as $rule) {
            FareRule::updateOrCreate(
                ['city_id' => $city->id, 'code' => $rule['code']],
                array_merge([
                    'context' => 'bus',
                    'per_km_fare' => 0,
                    'multiplier' => 1.0,
                    'is_active' => true,
                ], $rule),
            );
        }
    }
}
