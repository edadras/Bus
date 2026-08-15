<?php

namespace App\Domain\Support\Enums;

use App\Support\Concerns\HasLabel;

enum ComplaintCategory: string
{
    use HasLabel;

    case Driver = 'driver';
    case Delay = 'delay';
    case BusCondition = 'bus_condition';
    case AirConditioning = 'air_conditioning';
    case Crowding = 'crowding';
    case Misconduct = 'misconduct';
    case Payment = 'payment';
    case Technical = 'technical';
    case Other = 'other';
}
