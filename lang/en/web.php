<?php

return [

    'nav' => [
        'features' => 'Features',
        'live' => 'Live map',
        'wallet' => 'Wallet',
        'merchants' => 'Merchants',
        'cities' => 'Cities',
        'faq' => 'FAQ',
        'open_app' => 'Open the app',
        'menu' => 'Menu',
    ],

    'footer' => [
        'tagline' => 'One platform for urban transit and a city wallet: live bus tracking and honest arrival times, fare payment, and spending at partner venues — all on one account.',
        'quick_links' => 'Quick links',
        'passenger_app' => 'Passenger app',
        'driver_app' => 'Driver app',
        'merchant_app' => 'Merchant app',
        'admin_panel' => 'Admin panel',
        'contact' => 'Contact',
        'support_via_app' => 'Support: through the complaints section in the app',
        'active_city' => 'Active city: Bandar Abbas',
        'sample_notice_before' => 'The bus network data shown in this release is',
        'sample_notice_highlight' => 'sample data',
        'sample_notice_after' => 'and is not an official reference.',
    ],

    'viewer' => [
        'title' => 'Live map and arrival times',
        'public_view' => 'public view',
        'sample_badge' => 'Sample data',
        'choose_stop' => 'Choose a stop',
        'bus_with_passengers' => 'Bus :bus · :count passengers',
        'minutes' => 'min',
        'approximate' => 'approximate',
        'no_arrivals' => 'No bus is approaching this stop right now.',
        'wallet_heading' => 'Fares and wallet',
        'wallet_body' => 'To pay a fare by scanning a QR code, top up your wallet, see your ride history and raise a complaint, install the Hamsafar app.',
        'download_app' => 'Download for Android',
        'bus_number' => 'Bus :number',
        'popup_line' => 'Line :code — :destination',
        'popup_next_stop' => 'Next stop: :stop',
        'map_unavailable' => 'The map is unavailable',
    ],

    'native_app' => [
        'driver_title' => 'Driver app',
        'merchant_title' => 'Merchant app',
        'driver_body' => 'The driver app is built natively with Flutter and ships for Android and iOS. Background location and scanning the in-bus code both need the installed build.',
        'merchant_body' => 'The merchant app is built natively with Flutter and ships for Android and iOS. Displaying the rotating till QR and holding a credential securely both need the installed build.',
        'download_android' => 'Download for Android',
        'back_home' => 'Back to the home page',
        'source_path_before' => 'The app source lives at',
        'source_path_after' => 'in this repository.',
    ],

    'offline' => [
        'title' => 'No connection',
        'heading' => 'You are offline',
        'body' => 'Bus positions and your wallet balance need a connection. Nothing cached is shown, so you are never given a number that is no longer true.',
        'retry' => 'Try again',
    ],

    'payment' => [
        'sandbox_title' => 'Sandbox gateway',
        'sandbox_heading' => 'Sandbox payment gateway',
        'sandbox_badge' => 'Development',
        'sandbox_body' => 'This page stands in for a real gateway in development and exercises the whole payment path — start, return, and server-side verification.',
        'pay_success' => 'Pay successfully',
        'pay_cancel' => 'Cancel payment',
        'result_title' => 'Payment result',
        'succeeded' => 'Your wallet has been topped up',
        'pending' => 'Awaiting confirmation',
        'failed' => 'The payment did not go through',
        'close_hint' => 'You can close this page and return to the app; your balance updates on its own.',
        'back_home' => 'Back to the home page',
    ],

];
