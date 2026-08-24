<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Commission
    |--------------------------------------------------------------------------
    |
    | Withheld from every taxi fare, in basis points, unless the individual car
    | overrides it. 1000 bps is 10%.
    |
    */

    'default_commission_bps' => env('TAXI_COMMISSION_BPS', 1000),

    /*
    |--------------------------------------------------------------------------
    | Metered rides
    |--------------------------------------------------------------------------
    |
    | A metered ride is the one product whose price is unknown when it starts,
    | so it is also the only one that can end with a wallet that cannot cover
    | it. These settings are how that risk is bounded.
    |
    */

    'meter' => [
        // A metered ride will not start unless the wallet holds at least this
        // multiple of the tariff's minimum fare. Refusing at the kerb is far
        // better than stopping the car at the destination to argue.
        'minimum_start_balance_multiple' => env('TAXI_METER_START_MULTIPLE', 3),

        // Pings less accurate than this contribute no distance. A 200 m
        // accuracy circle can invent a kilometre over a few samples.
        'max_accuracy_meters' => env('TAXI_METER_MAX_ACCURACY', 60),

        // Above this, the jump between two samples is not a car, it is a bad
        // fix or a spoof; the distance is discarded and recorded as such.
        'max_plausible_speed_kmh' => env('TAXI_METER_MAX_SPEED', 140),

        // Two samples closer together than this are ignored, so a client
        // reporting every second cannot inflate the waiting clock.
        'min_sample_interval_seconds' => env('TAXI_METER_MIN_INTERVAL', 5),

        // A gap longer than this means the car went dark. The elapsed time is
        // not billed as waiting, because nobody can prove it was waiting.
        'max_sample_gap_seconds' => env('TAXI_METER_MAX_GAP', 120),

        // Hard ceiling on one ride. A meter that has run for eight hours is a
        // fault, not a fare, and force-closing beats billing it.
        'max_duration_minutes' => env('TAXI_METER_MAX_DURATION', 240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Charter quotes
    |--------------------------------------------------------------------------
    */

    'charter' => [
        // How long the driver's stated price stays valid. An amount set twenty
        // minutes ago belongs to a hire that has already driven away.
        'quote_ttl_seconds' => env('TAXI_CHARTER_TTL', 600),

        'max_amount' => env('TAXI_CHARTER_MAX', 50_000_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scanning
    |--------------------------------------------------------------------------
    */

    'scan' => [
        // How long a quote returned by a scan stays confirmable.
        'quote_ttl_seconds' => env('TAXI_QUOTE_TTL', 120),

        // Maximum distance between passenger and taxi at the moment of the
        // scan, when the passenger's phone supplies a position at all.
        'max_distance_meters' => env('TAXI_SCAN_MAX_DISTANCE', 150),
    ],

    /*
    |--------------------------------------------------------------------------
    | Live map
    |--------------------------------------------------------------------------
    */

    'live' => [
        // How long a taxi stays on the map after its last report.
        'ttl_seconds' => env('TAXI_LIVE_TTL', 120),

        // The public feed is a "taxis near me" feed and nothing more: a radius
        // is mandatory, capped here, and the payload carries no plate, no
        // driver and no passenger.
        'public_max_radius_meters' => env('TAXI_PUBLIC_RADIUS', 3000),
        'public_max_results' => env('TAXI_PUBLIC_LIMIT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settlement
    |--------------------------------------------------------------------------
    */

    'settlement' => [
        'minimum_amount' => env('TAXI_SETTLEMENT_MINIMUM', 500_000),
        'reference_prefix' => 'TXS',
    ],

];
