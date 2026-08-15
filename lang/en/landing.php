<?php

return [

    'title' => 'Smart urban transit',

    'hero' => [
        'live_badge' => ':city — live now',
        'heading_line_one' => 'Urban transit,',
        'heading_line_two' => 'simpler than ever',
        'body' => 'See where the bus is, how many minutes until it arrives, and pay the fare without cash or a ticket. The same wallet works at pools, gyms and partner shops.',
        'start' => 'Start a journey',
        'see_map' => 'See the live map',
        'active_buses' => 'buses in service',
        'active_lines' => 'active lines',
        'stops' => 'stops',
        'map_title' => 'Live map — :city',
        'sample_badge' => 'Sample data',
        'nearest_bus' => 'Nearest bus',
        'nearest_bus_eta' => '4 minutes',
        'nearest_bus_line' => 'Line 102 — towards Hormozgan University',
    ],

    'features' => [
        'heading' => 'One app, all of it',
        'subheading' => 'From the moment you are waiting at a stop until you step off the bus — and after that too.',
        'items' => [
            'tracking' => [
                'title' => 'Live bus tracking',
                'body' => 'Every bus on the map without refreshing the page. The connection is held open over a WebSocket.',
            ],
            'eta' => [
                'title' => 'Honest arrival estimates',
                'body' => 'The ETA is not distance over speed: live speed, how long this segment has actually taken at this hour on this kind of day, and the stops in between all count.',
            ],
            'qr' => [
                'title' => 'Pay by scanning a QR code',
                'body' => 'The code in the bus changes every 30 seconds and carries a cryptographic signature; a photograph of it is worth nothing.',
            ],
            'wallet' => [
                'title' => 'One city wallet',
                'body' => 'A single balance for the bus, the pool, the gym, the car park and partner shops.',
            ],
            'counting' => [
                'title' => 'Live passenger counting',
                'body' => 'The driver sees how many people are aboard as it changes, and the fleet is managed on real crowding rather than guesswork.',
            ],
            'complaints' => [
                'title' => 'Complaints you can follow',
                'body' => 'Raise a complaint with photos, the bus number and your position; the reply appears in the same thread.',
            ],
        ],
    ],

    'live' => [
        'heading' => 'Buses on the move',
        'body' => 'Every green marker is a bus in service. Select one to see its line, destination, next stop and estimated arrival.',
        'loading_lines' => 'Loading lines…',
        'open_full_map' => 'Open the full map',
    ],

    'wallet' => [
        'heading' => 'A wallet that is not only for the bus',
        'body' => 'Top up once and spend across the city’s services. Every transaction is written to a double-entry ledger, so every rial that moves can be traced and audited.',
        'points' => [
            'scan' => [
                'title' => 'Pay a fare by scanning',
                'body' => 'Scan the code in the bus; the fare is priced by the city’s own rules and debited.',
            ],
            'concessions' => [
                'title' => 'Concession fares',
                'body' => 'Student, senior and veteran discounts are applied to the fare automatically.',
            ],
            'history' => [
                'title' => 'A complete statement',
                'body' => 'Every payment is recorded with its amount, line, time and the balance left afterwards.',
            ],
            'refund' => [
                'title' => 'Refunds',
                'body' => 'A mistake is corrected with a reversing entry — history is never erased.',
            ],
        ],
        'card_balance' => 'Wallet balance',
        'card_active' => 'Active',
        'sample_rows' => [
            'fare' => 'Fare — line 102',
            'pool' => 'Sahel swimming pool',
            'topup' => 'Wallet top-up',
        ],
    ],

    'audiences' => [
        'heading' => 'For everyone with a part to play in the city',
        'passengers' => [
            'title' => 'Passengers',
            'items' => [
                'See nearby buses and when they arrive',
                'Pay fares without cash',
                'Review past journeys and transactions',
                'Raise a complaint with photos and follow the reply',
            ],
        ],
        'drivers' => [
            'title' => 'Drivers',
            'items' => [
                'Start a shift by scanning the code in the bus',
                'See the route, the next stop and the destination',
                'Live passenger count and shift takings',
                'Battery-aware location reporting',
            ],
        ],
        'businesses' => [
            'title' => 'Businesses',
            'items' => [
                'Take payment with the till’s own QR code',
                'Daily and monthly sales reports',
                'Refunds behind an explicit permission',
                'Regular, transparent settlement',
            ],
        ],
    ],

    'merchants' => [
        'heading' => 'Merchants beyond the bus network',
        'body' => 'Any business can join the city payment network. The customer scans the till’s code, the amount leaves their wallet and reaches the merchant net of commission — all in one balanced posting.',
        'cta' => 'Merchant app',
    ],

    'cities' => [
        'heading' => 'Cities covered',
        'body' => 'The platform was multi-city from the first day; adding another needs no change to the architecture.',
        'launched' => 'Live',
        'soon' => 'Coming soon',
    ],

    'faq' => [
        'heading' => 'Frequently asked questions',
        'items' => [
            'signup' => [
                'question' => 'Do I need an account to see the map and arrival times?',
                'answer' => 'No. Stops, lines, buses in service and estimated arrivals are open to everyone. An account is only needed for the wallet, paying fares, ride history and complaints.',
            ],
            'accuracy' => [
                'question' => 'How accurate is the arrival time?',
                'answer' => 'It is calculated from the bus’s live position and speed, how long that stretch has actually taken at this hour on this kind of day, and dwell time at the stops in between. Every estimate carries a confidence, and if the bus has stopped reporting you are told so.',
            ],
            'qr_photo' => [
                'question' => 'What if somebody photographs the QR code in the bus?',
                'answer' => 'The displayed code changes every 30 seconds and is signed with that bus’s own key. Each code works exactly once, so a photo or screenshot is worthless seconds later.',
            ],
            'double_charge' => [
                'question' => 'What if I am charged twice by mistake?',
                'answer' => 'Payment is built so it cannot happen: each scan carries a unique identifier, and a retry with the same identifier returns the original transaction. If something does go wrong, raise it through complaints; a refund is made by writing a reversing entry.',
            ],
            'location' => [
                'question' => 'Is my location stored?',
                'answer' => 'Sending your position is optional and is used only to detect that you have got off, during that ride. It is kept for at most 24 hours and then deleted, and it is never given to other users.',
            ],
            'official_data' => [
                'question' => 'Is the line and stop data official?',
                'answer' => 'In this release the Bandar Abbas network data is sample data and is labelled as such wherever it appears. Once official data is provided by the transit authority it replaces this through the import pipeline and is marked as official.',
            ],
        ],
    ],

    'cta' => [
        'heading' => 'Start now',
        'body' => 'The passenger view runs straight in the browser with nothing to install. Add it to your home screen and it opens like any other app.',
        'passenger' => 'Open the passenger app',
        'driver' => 'Driver sign-in',
    ],

];
