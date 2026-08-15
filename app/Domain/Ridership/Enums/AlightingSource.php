<?php

namespace App\Domain\Ridership\Enums;

use App\Support\Concerns\HasLabel;

enum AlightingSource: string
{
    use HasLabel;

    case Geofence = 'geofence';
    case Divergence = 'divergence';
    case Manual = 'manual';
    case TripEnded = 'trip_ended';
    case Timeout = 'timeout';
    case Driver = 'driver';
}
