<?php

namespace Database\Factories;

use App\Domain\Network\Models\City;
use App\Domain\SchoolTransport\Models\School;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Domain\SchoolTransport\Models\SchoolServiceRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SchoolServiceRoute> */
class SchoolServiceRouteFactory extends Factory
{
    protected $model = SchoolServiceRoute::class;

    public function definition(): array
    {
        return [
            'school_company_id' => SchoolCompany::factory(),
            'school_id' => School::factory(),
            'city_id' => City::factory(),
            'name' => 'مسیر '.fake()->unique()->numberBetween(1, 999),
            'shift' => 'both',
            'capacity' => 15,
            'pickup_starts_at' => '06:30:00',
            'dropoff_starts_at' => '13:15:00',
            'is_active' => true,
        ];
    }
}
