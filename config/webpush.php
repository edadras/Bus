<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID
    |--------------------------------------------------------------------------
    |
    | Generate a key pair once per deployment with:
    |
    |     php artisan webpush:vapid
    |
    | The public key is handed to browsers when they subscribe; the private key
    | signs every push and must never leave the server. Rotating the pair
    | invalidates every stored subscription, so treat it as a one-time setup.
    |
    */

    'vapid' => [
        'subject' => env('WEBPUSH_VAPID_SUBJECT', env('APP_URL', 'https://localhost')),
        'public_key' => env('WEBPUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEBPUSH_VAPID_PRIVATE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    */

    // Seconds a push service should hold an undelivered message. Arrival alerts
    // are worthless once the bus has gone, so this is deliberately short.
    'ttl' => env('WEBPUSH_TTL', 300),

    'urgency' => 'high',

    // Pushes are sent in one batch per notification, so a rider with three
    // devices costs one round trip rather than three.
    'batch_size' => 100,
];
