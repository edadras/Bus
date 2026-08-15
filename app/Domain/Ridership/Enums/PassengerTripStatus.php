<?php

namespace App\Domain\Ridership\Enums;

use App\Support\Concerns\HasLabel;

enum PassengerTripStatus: string
{
    use HasLabel;

    case Active = 'active';
    /** Alighting was inferred but confidence was below threshold. */
    case PendingAlighting = 'pending_alighting';
    case Completed = 'completed';
    case ForceClosed = 'force_closed';
    case Cancelled = 'cancelled';

    public function occupiesSeat(): bool
    {
        return in_array($this, [self::Active, self::PendingAlighting], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::PendingAlighting => 'warning',
            self::Completed => 'info',
            default => 'neutral',
        };
    }
}
