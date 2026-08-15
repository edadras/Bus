<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Enums\WalletOwnerType;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;

/**
 * Resolves the platform's own counter accounts. Every passenger debit has a
 * matching credit somewhere; these are the somewheres.
 */
class SystemAccountRegistry
{
    /** @var array<string, Wallet> */
    private array $cache = [];

    public function get(string $key): Wallet
    {
        $ref = config("wallet.system_accounts.$key");

        if ($ref === null) {
            throw new \InvalidArgumentException("Unknown system account [$key].");
        }

        return $this->cache[$ref] ??= Wallet::firstOrCreate(
            ['account_ref' => $ref],
            [
                'owner_type' => WalletOwnerType::System,
                'owner_id' => null,
                'currency' => config('wallet.currency'),
                'balance' => 0,
                'status' => WalletStatus::Active,
            ],
        );
    }

    public function gatewayClearing(): Wallet
    {
        return $this->get('gateway_clearing');
    }

    public function fareRevenue(): Wallet
    {
        return $this->get('fare_revenue');
    }

    public function commissionRevenue(): Wallet
    {
        return $this->get('commission_revenue');
    }

    public function settlementPayable(): Wallet
    {
        return $this->get('settlement_payable');
    }

    public function adjustments(): Wallet
    {
        return $this->get('adjustments');
    }

    /** Ensure every configured account exists; called by the installer seeder. */
    public function ensureAll(): void
    {
        foreach (array_keys((array) config('wallet.system_accounts')) as $key) {
            $this->get($key);
        }
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
