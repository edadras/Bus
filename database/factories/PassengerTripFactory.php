<?php

namespace Database\Factories;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\Operations\Models\Trip;
use App\Domain\Ridership\Enums\PassengerTripStatus;
use App\Domain\Ridership\Models\PassengerTrip;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PassengerTrip> */
class PassengerTripFactory extends Factory
{
    protected $model = PassengerTrip::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'trip_id' => Trip::factory(),
            'bus_id' => Bus::factory(),
            'city_id' => City::factory(),
            'status' => PassengerTripStatus::Active,
            'boarded_at' => now()->subMinutes(10),
            'fare_amount' => 50_000,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PassengerTripStatus::Completed,
            'alighted_at' => now(),
            'duration_seconds' => 900,
            'distance_meters' => 4_200,
            'alighting_confidence' => 0.92,
            'alighting_source' => 'auto',
        ]);
    }

    /** Detected as probably-alighted, but not yet closed. */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => PassengerTripStatus::PendingAlighting,
            'alighting_confidence' => 0.55,
        ]);
    }
}
