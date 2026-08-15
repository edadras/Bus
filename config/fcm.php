<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (HTTP v1)
    |--------------------------------------------------------------------------
    |
    | Native push for the Android and iOS builds. FCM v1 authenticates with a
    | short-lived OAuth token minted from a service-account key, so the two
    | values below are all a deployment needs:
    |
    |   FCM_PROJECT_ID          the Firebase project id
    |   FCM_CREDENTIALS         absolute path to the service-account JSON
    |
    | The JSON contains a private key. Keep it outside the web root, out of
    | version control, and readable only by the application user.
    |
    | Left unset, native push is simply disabled: the apps still receive
    | everything through the in-app inbox and the live socket, and nothing
    | else changes.
    |
    */

    'project_id' => env('FCM_PROJECT_ID'),

    'credentials' => env('FCM_CREDENTIALS'),

    // Seconds a push service should hold an undelivered message. Arrival
    // alerts are worthless once the bus has gone.
    'ttl' => env('FCM_TTL', 300),

    'timeout' => env('FCM_TIMEOUT', 8),

];
