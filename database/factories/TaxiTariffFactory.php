<?php

namespace Database\Factories;

use App\Domain\Network\Models\City;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Models\TaxiTariff;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TaxiTariff> */
class TaxiTariffFactory extends Factory
{
    protected $model = TaxiTariff::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => 'تعرفه روز',
            'service_type' => TaxiServiceType::Meter,
            // Round figures on purpose: a test that asserts a fare should fail
            // because the maths is wrong, not because the arithmetic is fiddly.
            'base_fare' => 200_000,
            'per_km_fare' => 100_000,
            'per_minute_waiting_fare' => 20_000,
            'minimum_fare' => 300_000,
            'waiting_speed_kmh' => 5,
            'multiplier' => 1,
            'priority' => 10,
            'is_active' => true,
        ];
    }

    public function night(): static
    {
        return $this->state(fn () => [
            'name' => 'تعرفه شب',
            'valid_from_time' => '22:00:00',
            'valid_to_time' => '06:00:00',
            'multiplier' => 1.25,
            'priority' => 20,
        ]);
    }
}
