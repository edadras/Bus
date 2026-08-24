<?php

namespace Database\Factories;

use App\Domain\Network\Models\City;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Enums\TaxiStatus;
use App\Domain\Taxi\Models\Taxi;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Taxi> */
class TaxiFactory extends Factory
{
    protected $model = Taxi::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'taxi_number' => (string) fake()->unique()->numberBetween(1000, 9999),
            'plate' => fake()->bothify('## ? ### ایران ۸۴'),
            'color' => 'yellow',
            'capacity' => 4,
            'status' => TaxiStatus::Idle,
        ];
    }

    public function inService(): static
    {
        return $this->state(fn () => ['status' => TaxiStatus::Active]);
    }

    /** Licensed for one mode only, which is how a line-only taxi is set up. */
    public function onlyMode(TaxiServiceType $mode): static
    {
        return $this->state(fn () => ['allowed_modes' => [$mode->value]]);
    }
}
