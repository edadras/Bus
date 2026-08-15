<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    |
    | `log` is the default on purpose: a deployment without a provider contract
    | still signs users in, and the code lands in the sms log channel. Point
    | this at a real driver once a contract exists — nothing else changes.
    |
    */

    'default' => env('SMS_GATEWAY', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | Iranian operators require verification codes to travel through a pattern
    | approved in advance; free-text OTPs are filtered. `otp_template` is that
    | pattern's name (Kavenegar) or numeric id (SMS.ir). Leave it empty and the
    | code goes as normal text, which is right for a provider that allows it.
    |
    */

    'gateways' => [

        'log' => [
            'driver' => 'log',
        ],

        'kavenegar' => [
            'driver' => 'kavenegar',
            'api_key' => env('KAVENEGAR_API_KEY'),
            'sender' => env('KAVENEGAR_SENDER'),
            'otp_template' => env('KAVENEGAR_OTP_TEMPLATE'),
        ],

        'smsir' => [
            'driver' => 'smsir',
            'api_key' => env('SMSIR_API_KEY'),
            'line_number' => env('SMSIR_LINE_NUMBER'),
            'otp_template' => env('SMSIR_OTP_TEMPLATE'),
        ],

    ],

    // Kept short. An SMS provider is on the critical path of sign-in, and a
    // slow one must degrade to "code not delivered" rather than hold the
    // request open.
    'timeout' => env('SMS_TIMEOUT', 8),

];
