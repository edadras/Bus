<?php

namespace Database\Factories;

use App\Domain\Merchant\Enums\MerchantStatus;
use App\Domain\Merchant\Enums\MerchantType;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Network\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Merchant> */
class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => fake()->company(),
            'code' => strtoupper(fake()->unique()->bothify('MRC#####')),
            'type' => fake()->randomElement(MerchantType::cases()),
            'status' => MerchantStatus::Active,
            'commission_bps' => 150,
            'allows_refund' => true,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => MerchantStatus::Suspended]);
    }
}
