<?php

namespace Database\Factories;

use App\Domain\Network\Models\City;
use App\Domain\SchoolTransport\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<School> */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => 'دبستان '.fake()->unique()->numberBetween(1, 9999),
            'gender' => 'mixed',
            'level' => 'primary',
            'lat' => 27.1832,
            'lng' => 56.2666,
            'starts_at' => '07:30:00',
            'ends_at' => '13:00:00',
            'is_active' => true,
        ];
    }
}
