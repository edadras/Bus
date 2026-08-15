<?php

namespace Database\Factories;

use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Models\BusStop;
use App\Domain\Network\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusStop> */
class BusStopFactory extends Factory
{
    protected $model = BusStop::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'code' => strtoupper(fake()->unique()->bothify('ST###')),
            'name' => 'ایستگاه '.fake()->unique()->numberBetween(1, 9999),
            // Scattered around Bandar Abbas so distances are realistic.
            'lat' => fake()->randomFloat(6, 27.15, 27.22),
            'lng' => fake()->randomFloat(6, 56.20, 56.34),
            'geofence_radius' => 60,
            'is_active' => true,
            'provenance' => NetworkProvenance::Sample,
        ];
    }

    /** Place the stop at an exact position, for deterministic geometry tests. */
    public function at(float $lat, float $lng): static
    {
        return $this->state(fn () => ['lat' => $lat, 'lng' => $lng]);
    }
}
