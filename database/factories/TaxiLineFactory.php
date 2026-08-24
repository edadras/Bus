<?php

namespace Database\Factories;

use App\Domain\Network\Models\City;
use App\Domain\Taxi\Models\TaxiLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TaxiLine> */
class TaxiLineFactory extends Factory
{
    protected $model = TaxiLine::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'code' => 'T'.fake()->unique()->numberBetween(1, 999),
            'name' => 'خط '.fake()->numberBetween(1, 40),
            'origin_label' => 'میدان شهدا',
            'destination_label' => 'اسکله',
            'flat_fare' => 150_000,
            'is_active' => true,
        ];
    }
}
