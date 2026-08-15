<?php

namespace Database\Factories;

use App\Domain\Fleet\Enums\DriverStatus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Driver> */
class DriverFactory extends Factory
{
    protected $model = Driver::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'city_id' => City::factory(),
            'national_code' => fake()->unique()->numerify('##########'),
            'license_number' => strtoupper(fake()->unique()->bothify('HR#####')),
            'license_expires_at' => now()->addYears(2),
            'status' => DriverStatus::Active,
            'hired_at' => now()->subYear(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => DriverStatus::PendingApproval]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => DriverStatus::Suspended]);
    }

    public function withExpiredLicense(): static
    {
        return $this->state(fn () => ['license_expires_at' => now()->subDay()]);
    }
}
