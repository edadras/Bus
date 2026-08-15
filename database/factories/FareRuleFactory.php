<?php

namespace Database\Factories;

use App\Domain\Network\Models\City;
use App\Domain\Wallet\Models\FareRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FareRule> */
class FareRuleFactory extends Factory
{
    protected $model = FareRule::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => 'کرایه آزمایشی',
            'code' => strtoupper(fake()->unique()->bothify('FARE####')),
            'context' => 'bus',
            'base_fare' => 50_000,
            'per_km_fare' => 0,
            'multiplier' => 1.0,
            'priority' => 10,
            'is_active' => true,
        ];
    }
}
