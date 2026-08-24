<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Commission
    |--------------------------------------------------------------------------
    |
    | Withheld from each school service fee, in basis points, unless the
    | company overrides it. 500 bps is 5%.
    |
    */

    'default_commission_bps' => env('SCHOOL_COMMISSION_BPS', 500),

    /*
    |--------------------------------------------------------------------------
    | Live tracking
    |--------------------------------------------------------------------------
    |
    | A van's position is visible to the families it is carrying, while it is
    | carrying them, and to nobody else at any other time. These settings only
    | bound how that works; the rule itself is enforced in the domain.
    |
    */

    'live' => [
        'ttl_seconds' => env('SCHOOL_LIVE_TTL', 180),

        // Average urban speed used for the parent's "about N minutes away".
        // Deliberately conservative: a van that arrives early is a nuisance, a
        // van a parent missed is a child on the pavement.
        'eta_speed_kmh' => env('SCHOOL_ETA_SPEED', 22),

        // Below this distance the app says "arriving" rather than a figure no
        // estimate can honestly support.
        'arriving_within_meters' => env('SCHOOL_ARRIVING_WITHIN', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runs
    |--------------------------------------------------------------------------
    */

    'trips' => [
        // How far ahead runs are created, so a driver opening the app before
        // dawn already has the morning's manifest.
        'schedule_days_ahead' => env('SCHOOL_SCHEDULE_DAYS', 2),

        // A run still open after this is a driver who forgot, not a run.
        'auto_close_after_hours' => env('SCHOOL_TRIP_AUTO_CLOSE', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoicing
    |--------------------------------------------------------------------------
    */

    'invoices' => [
        'due_days' => env('SCHOOL_INVOICE_DUE_DAYS', 7),
        'reference_prefix' => 'SCH',
    ],

    'contracts' => [
        'reference_prefix' => 'SC',
    ],

];
