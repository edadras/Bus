<?php

namespace Database\Factories;

use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusLine> */
class BusLineFactory extends Factory
{
    protected $model = BusLine::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'code' => (string) fake()->unique()->numberBetween(100, 999),
            'name' => 'خط '.fake()->unique()->numberBetween(100, 999),
            'color' => fake()->hexColor(),
            'is_active' => true,
            'provenance' => NetworkProvenance::Sample,
        ];
    }
}
