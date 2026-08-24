<?php

namespace Database\Factories;

use App\Domain\Network\Models\City;
use App\Domain\SchoolTransport\Enums\SchoolCompanyStatus;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SchoolCompany> */
class SchoolCompanyFactory extends Factory
{
    protected $model = SchoolCompany::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => 'سرویس '.fake()->unique()->numberBetween(1, 9999),
            'code' => 'SC'.Str::upper(Str::random(6)),
            // Unapproved by default: the approval step is the product, and a
            // factory that skipped it would let tests pass that should not.
            'status' => SchoolCompanyStatus::PendingApproval,
            'phone' => '076'.fake()->numerify('#######'),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => SchoolCompanyStatus::Active,
            'approved_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => SchoolCompanyStatus::Suspended]);
    }
}
