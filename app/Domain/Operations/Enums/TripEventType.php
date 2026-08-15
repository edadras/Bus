<?php

namespace App\Domain\Operations\Enums;

use App\Support\Concerns\HasLabel;

enum TripEventType: string
{
    use HasLabel;

    case Started = 'started';
    case Paused = 'paused';
    case Resumed = 'resumed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case StopArrived = 'stop_arrived';
    case StopDeparted = 'stop_departed';
    case OffRoute = 'off_route';
    case BackOnRoute = 'back_on_route';
    case IdleDetected = 'idle_detected';
    case IdleCleared = 'idle_cleared';
    case SpeedingDetected = 'speeding_detected';
    case PassengerBoarded = 'passenger_boarded';
    case PassengerAlighted = 'passenger_alighted';
    case GpsLost = 'gps_lost';
    case GpsRestored = 'gps_restored';
}
