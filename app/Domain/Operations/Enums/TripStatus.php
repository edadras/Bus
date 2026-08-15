<?php

namespace App\Domain\Operations\Enums;

use App\Support\Concerns\HasLabel;

enum TripStatus: string
{
    use HasLabel;

    case Scheduled = 'scheduled';
    case Starting = 'starting';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Statuses in which the trip still occupies the bus and the driver. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::Starting, self::Active, self::Paused], true);
    }

    /** Statuses in which GPS pings and fare payments are accepted. */
    public function acceptsTelemetry(): bool
    {
        return in_array($this, [self::Starting, self::Active, self::Paused], true);
    }

    public function acceptsBoarding(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Starting, self::Scheduled => 'info',
            self::Paused => 'warning',
            self::Completed => 'neutral',
            self::Cancelled => 'danger',
        };
    }
}
