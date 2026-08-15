<?php

namespace Database\Factories;

use App\Domain\Fleet\Enums\BusStatus;
use App\Domain\Fleet\Models\Bus;
use App\Domain\Network\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bus> */
class BusFactory extends Factory
{
    protected $model = Bus::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'bus_number' => (string) fake()->unique()->numberBetween(100, 9999),
            'plate' => fake()->bothify('## ? ### ایران ۸۴'),
            'capacity_seated' => 30,
            'capacity_standing' => 20,
            'has_air_conditioning' => true,
            'status' => BusStatus::Idle,
        ];
    }

    public function inService(): static
    {
        return $this->state(fn () => ['status' => BusStatus::Active]);
    }

    public function underMaintenance(): static
    {
        return $this->state(fn () => ['status' => BusStatus::Maintenance]);
    }
}
