<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            // Unique, valid Iranian mobile numbers without colliding with the
            // fixed numbers used by the demo seeder.
            'mobile' => '9899'.fake()->unique()->numerify('########'),
            'mobile_verified_at' => now(),
            'status' => UserStatus::Active,
            'locale' => 'fa',
        ];
    }

    public function withPassword(string $password = 'password'): static
    {
        return $this->state(fn () => ['password' => Hash::make($password)]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }
}
