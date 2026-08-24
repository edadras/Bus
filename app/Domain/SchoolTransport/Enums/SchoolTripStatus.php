<?php

namespace App\Domain\SchoolTransport\Enums;

use App\Support\Concerns\HasLabel;

enum SchoolTripStatus: string
{
    use HasLabel;

    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Whether a guardian may watch the vehicle right now.
     *
     * This is the whole privacy rule for the live map in one place: a van's
     * position is visible to the families it is carrying, while it is carrying
     * them, and to nobody else at any other time.
     */
    public function allowsLiveTracking(): bool
    {
        return $this === self::InProgress;
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::InProgress], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::InProgress => 'success',
            self::Scheduled => 'info',
            self::Completed => 'neutral',
            self::Cancelled => 'danger',
        };
    }
}
