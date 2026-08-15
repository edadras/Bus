<?php

namespace App\Domain\Fleet\Enums;

use App\Support\Concerns\HasLabel;

enum DriverStatus: string
{
    use HasLabel;

    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    public function canDrive(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::PendingApproval => 'info',
            self::Suspended => 'danger',
            self::Inactive => 'neutral',
        };
    }
}
