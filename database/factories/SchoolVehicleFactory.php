<?php

namespace Database\Factories;

use App\Domain\Network\Models\City;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Domain\SchoolTransport\Models\SchoolVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SchoolVehicle> */
class SchoolVehicleFactory extends Factory
{
    protected $model = SchoolVehicle::class;

    public function definition(): array
    {
        return [
            'school_company_id' => SchoolCompany::factory(),
            'city_id' => City::factory(),
            'plate' => fake()->unique()->bothify('## ? ### ایران ۸۴'),
            'capacity' => 15,
            'status' => 'active',
            'has_seatbelts' => true,
            'insurance_expires_at' => today()->addYear(),
        ];
    }

    public function uninsured(): static
    {
        return $this->state(fn () => ['insurance_expires_at' => today()->subDay()]);
    }
}
