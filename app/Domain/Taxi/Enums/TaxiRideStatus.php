<?php

namespace App\Domain\Taxi\Enums;

use App\Support\Concerns\HasLabel;

enum TaxiRideStatus: string
{
    use HasLabel;

    /** The meter is running, or the passenger is aboard a line taxi. */
    case Active = 'active';

    case Completed = 'completed';

    /**
     * Ended, priced, and the wallet could not cover it. The ride is finished
     * and the debt is recorded — never written off silently, and never left as
     * a half-open ride that would keep the seat occupied forever.
     */
    case Unpaid = 'unpaid';

    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Active => 'info',
            self::Unpaid => 'danger',
            self::Cancelled => 'neutral',
        };
    }
}
