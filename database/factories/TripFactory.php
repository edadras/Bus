<?php

namespace Database\Factories;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\City;
use App\Domain\Operations\Enums\TripStatus;
use App\Domain\Operations\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Trip> */
class TripFactory extends Factory
{
    protected $model = Trip::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'bus_id' => Bus::factory(),
            'driver_id' => Driver::factory(),
            'bus_line_id' => BusLine::factory(),
            'route_id' => BusRoute::factory(),
            'status' => TripStatus::Active,
            'started_at' => now()->subMinutes(20),
            'last_ping_at' => now(),
            'current_lat' => 27.1832,
            'current_lng' => 56.2666,
            'current_speed_kmh' => 24.0,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => TripStatus::Completed,
            'ended_at' => now(),
        ]);
    }

    /** No recent ping, so ETA confidence should drop and staleness show. */
    public function stale(): static
    {
        return $this->state(fn () => ['last_ping_at' => now()->subMinutes(15)]);
    }
}
