<?php

namespace Database\Factories;

use App\Domain\Network\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<City> */
class CityFactory extends Factory
{
    protected $model = City::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->city(),
            'center_lat' => 27.1832,
            'center_lng' => 56.2666,
            'default_zoom' => 13,
            'is_active' => true,
            'is_launched' => true,
        ];
    }
}
