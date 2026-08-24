<?php

namespace App\Domain\SchoolTransport\Enums;

use App\Support\Concerns\HasLabel;

/**
 * The life of a contract.
 *
 * `Approved` and `Active` are deliberately separate. A company can accept a
 * family before it knows which van they will be on; the contract only becomes
 * active once it has a route, because that is the moment a seat exists and the
 * child starts appearing on a run.
 */
enum SchoolContractStatus: string
{
    use HasLabel;

    case Requested = 'requested';
    case Approved = 'approved';
    case Active = 'active';
    case Suspended = 'suspended';
    case Ended = 'ended';
    case Rejected = 'rejected';

    public function isLive(): bool
    {
        return $this === self::Active;
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Active, self::Suspended], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Approved => 'info',
            self::Requested, self::Suspended => 'warning',
            self::Rejected, self::Ended => 'neutral',
        };
    }
}
