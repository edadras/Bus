<?php

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\SchoolTransport\Models\School;
use App\Domain\SchoolTransport\Models\SchoolStudent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SchoolStudent> */
class SchoolStudentFactory extends Factory
{
    protected $model = SchoolStudent::class;

    public function definition(): array
    {
        return [
            'guardian_user_id' => User::factory(),
            'city_id' => City::factory(),
            'school_id' => School::factory(),
            'first_name' => fake()->randomElement(['سارا', 'امیر', 'مریم', 'رضا', 'نگار']),
            'last_name' => fake()->randomElement(['محمدی', 'احمدی', 'کریمی']),
            'grade' => (string) fake()->numberBetween(1, 6),
            'pickup_address' => 'خیابان '.fake()->numberBetween(1, 40),
            'pickup_lat' => 27.1832,
            'pickup_lng' => 56.2666,
            'is_active' => true,
        ];
    }
}
