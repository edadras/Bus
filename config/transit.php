<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default City
    |--------------------------------------------------------------------------
    |
    | The platform is multi-city from day one. This slug identifies the city
    | used when a request does not carry an explicit city context (header,
    | subdomain or authenticated user's home city).
    |
    */

    'default_city' => env('TRANSIT_DEFAULT_CITY', 'bandar-abbas'),

    /*
    |--------------------------------------------------------------------------
    | Default Locale
    |--------------------------------------------------------------------------
    |
    | Deliberately not read from app.locale. Laravel's App::setLocale() writes
    | app.locale as well as the translator's locale, so a request that asked
    | for English would rewrite the very default the next request falls back
    | to. Under a long-lived worker that turns one English visitor into an
    | English interface for every Persian rider after them. This key is only
    | ever read.
    |
    */

    'default_locale' => env('APP_LOCALE', 'fa'),

    'supported_locales' => ['fa', 'en'],

    /*
    |--------------------------------------------------------------------------
    | GPS Ingest
    |--------------------------------------------------------------------------
    */

    'gps' => [
        // Minimum seconds between two accepted pings from the same trip.
        'min_interval_seconds' => env('TRANSIT_GPS_MIN_INTERVAL', 3),

        // Pings less accurate than this (metres) are rejected outright.
        'max_accuracy_meters' => env('TRANSIT_GPS_MAX_ACCURACY', 100),

        // Speed above which a ping is considered physically impossible (km/h).
        'max_plausible_speed_kmh' => env('TRANSIT_GPS_MAX_SPEED', 140),

        // Distance (metres) from the route polyline before a bus is "off-route".
        'off_route_threshold_meters' => env('TRANSIT_GPS_OFF_ROUTE', 150),

        // Consecutive off-route pings required before raising the event.
        'off_route_ping_threshold' => 3,

        // Radius (metres) around a stop that counts as "at the stop".
        'stop_geofence_meters' => env('TRANSIT_STOP_GEOFENCE', 60),

        // Speed under which the bus counts as stopped (km/h).
        'idle_speed_kmh' => 3,

        // Seconds under the idle threshold before the bus is flagged stopped.
        'idle_seconds' => 120,

        // Adaptive reporting cadence hints returned to the driver app (seconds).
        'cadence' => [
            'moving' => 5,
            'approaching_stop' => 3,
            'idle' => 20,
            'off_shift' => 0,
        ],

        // How long a raw location row is retained before pruning (days).
        'retention_days' => env('TRANSIT_GPS_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | ETA Engine
    |--------------------------------------------------------------------------
    |
    | The v1 engine is deterministic: it blends live speed, the historical
    | segment travel time for the current time bucket, and a per-segment
    | traffic factor. Weights must sum to 1.0.
    |
    */

    'eta' => [
        'weights' => [
            'live_speed' => 0.35,
            'historical' => 0.45,
            'baseline' => 0.20,
        ],

        // Fallback average speed when nothing else is known (km/h).
        'baseline_speed_kmh' => env('TRANSIT_ETA_BASELINE_SPEED', 22),

        // Fixed dwell time added per intermediate stop (seconds).
        'dwell_seconds_per_stop' => 25,

        // Minimum historical samples before history is trusted.
        'min_historical_samples' => 5,

        // Cache TTL for a computed arrival board (seconds).
        'cache_ttl' => 20,

        // Arrivals further out than this are not published (seconds).
        'max_horizon_seconds' => 3600,

        // A trip with no ping newer than this is treated as stale (seconds).
        'stale_after_seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bus QR Codes
    |--------------------------------------------------------------------------
    |
    | Static printed QR codes carry a secret that is never transmitted by the
    | client alone; the client must present a rotating, HMAC-signed token that
    | is derived from the secret. See App\Domain\Fleet\Services\BusQrService.
    |
    */

    'qr' => [
        // Rotating token time-step in seconds (TOTP-like).
        'rotation_seconds' => env('TRANSIT_QR_ROTATION', 30),

        // Number of past/future steps accepted for clock drift.
        'drift_steps' => 1,

        // How long a presented nonce is remembered to block replays (seconds).
        'replay_window_seconds' => 180,

        // Payload version marker, bumped when the token format changes.
        'version' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Boarding / Alighting
    |--------------------------------------------------------------------------
    */

    'ridership' => [
        // A passenger may not board the same trip again within this window (s).
        'reboard_cooldown_seconds' => 300,

        // Passenger must be within this many metres of the bus when boarding.
        'boarding_proximity_meters' => 300,

        // Confidence (0-1) required to auto-close a passenger trip.
        'alighting_confidence_threshold' => 0.75,

        // Consecutive out-of-vehicle signals before alighting is considered.
        'alighting_signal_threshold' => 2,

        // Passenger trips still open this long after the bus trip ends are
        // force-closed by the scheduler (minutes).
        'auto_close_after_minutes' => 45,
    ],

    /*
    |--------------------------------------------------------------------------
    | Map Provider
    |--------------------------------------------------------------------------
    |
    | The mapping layer is abstracted behind App\Domain\Mapping\Contracts\
    | MapProvider so the provider can be swapped without touching callers.
    |
    */

    'map' => [
        'provider' => env('MAP_PROVIDER', 'osm'),

        'providers' => [
            'osm' => [
                'tile_url' => env('MAP_OSM_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
                'attribution' => '© OpenStreetMap contributors',
                'max_zoom' => 19,
            ],
            'mapbox' => [
                'tile_url' => env('MAP_MAPBOX_TILE_URL'),
                'token' => env('MAP_MAPBOX_TOKEN'),
                'attribution' => '© Mapbox © OpenStreetMap',
                'max_zoom' => 20,
            ],
            'google' => [
                'tile_url' => env('MAP_GOOGLE_TILE_URL'),
                'token' => env('MAP_GOOGLE_TOKEN'),
                'attribution' => '© Google',
                'max_zoom' => 20,
            ],
            'neshan' => [
                'tile_url' => env('MAP_NESHAN_TILE_URL'),
                'token' => env('MAP_NESHAN_TOKEN'),
                'attribution' => '© Neshan',
                'max_zoom' => 18,
            ],
        ],

        'default_view' => [
            'lat' => env('MAP_DEFAULT_LAT', 27.1832),
            'lng' => env('MAP_DEFAULT_LNG', 56.2666),
            'zoom' => env('MAP_DEFAULT_ZOOM', 12),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Live State (Redis)
    |--------------------------------------------------------------------------
    */

    'live' => [
        'key_prefix' => 'live',
        // A live bus entry expires this long after its last ping (seconds).
        'bus_ttl' => 180,
        'channel_prefix' => 'transit',
    ],

    /*
    |--------------------------------------------------------------------------
    | OTP Authentication
    |--------------------------------------------------------------------------
    */

    'otp' => [
        'length' => 5,
        'ttl_seconds' => 120,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
        'daily_request_limit' => 10,
        // In local/testing this fixed code is accepted to ease development.
        'testing_code' => env('OTP_TESTING_CODE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Provenance
    |--------------------------------------------------------------------------
    |
    | Network data (stops, lines, routes) is tagged with its provenance so the
    | UI can never present seeded demo geometry as verified official data.
    |
    */

    'provenance' => [
        'default' => 'sample',
        'allowed' => ['official', 'community', 'sample'],
    ],
];
