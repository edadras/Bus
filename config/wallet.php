<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | All monetary amounts are stored as integer minor units to avoid any
    | floating point drift. For IRR the minor unit is the rial itself
    | (scale 0); the UI may still display Toman by dividing by 10.
    |
    */

    'currency' => env('WALLET_CURRENCY', 'IRR'),
    'scale' => env('WALLET_SCALE', 0),
    'display_unit' => env('WALLET_DISPLAY_UNIT', 'toman'), // rial|toman

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'min_topup' => 10_000,
        'max_topup' => 50_000_000,
        'max_balance' => 200_000_000,
        'max_daily_spend' => 50_000_000,
        // Allowed negative balance (fare tolerance), 0 disables overdraft.
        'overdraft' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | System Accounts
    |--------------------------------------------------------------------------
    |
    | The ledger is double entry: every posting balances across at least two
    | accounts. These are the platform-owned counter accounts.
    |
    */

    'system_accounts' => [
        'gateway_clearing' => 'system:gateway-clearing',
        'fare_revenue' => 'system:fare-revenue',
        'commission_revenue' => 'system:commission-revenue',
        'settlement_payable' => 'system:settlement-payable',
        'adjustments' => 'system:adjustments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Gateways are resolved through App\Domain\Payment\Contracts\PaymentGateway.
    | The bundled `manual` driver settles top-ups through an admin approval and
    | the `sandbox` driver auto-approves in non-production environments.
    |
    */

    'gateway' => env('PAYMENT_GATEWAY', 'sandbox'),

    'gateways' => [
        'sandbox' => [
            'driver' => 'sandbox',
            'enabled' => env('APP_ENV') !== 'production',
        ],
        'manual' => [
            'driver' => 'manual',
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Merchant Settlement
    |--------------------------------------------------------------------------
    */

    'settlement' => [
        'default_commission_bps' => env('MERCHANT_COMMISSION_BPS', 150), // 1.50%
        'min_settlement_amount' => 1_000_000,
        'cycle' => 'weekly', // daily|weekly|monthly
    ],

    /*
    |--------------------------------------------------------------------------
    | Fraud Controls
    |--------------------------------------------------------------------------
    */

    'fraud' => [
        'max_payments_per_minute' => 4,
        'max_payments_per_hour' => 40,
        'max_failed_scans_per_hour' => 20,
        'velocity_amount_per_hour' => 100_000_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */

    'idempotency' => [
        'ttl_hours' => 48,
        'header' => 'Idempotency-Key',
    ],
];
