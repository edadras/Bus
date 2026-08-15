<?php

namespace Database\Factories;

use App\Domain\Wallet\Enums\WalletOwnerType;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 *
 * Note: a factory-made balance bypasses the ledger and is therefore only
 * appropriate for tests that never inspect the books. Anything asserting on
 * balances should credit through WalletService so the postings exist.
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return [
            'owner_type' => WalletOwnerType::User,
            'owner_id' => null,
            'currency' => config('wallet.currency'),
            'balance' => 0,
            'status' => WalletStatus::Active,
        ];
    }

    public function frozen(): static
    {
        return $this->state(fn () => [
            'status' => WalletStatus::Frozen,
            'frozen_at' => now(),
            'frozen_reason' => 'test',
        ]);
    }
}
