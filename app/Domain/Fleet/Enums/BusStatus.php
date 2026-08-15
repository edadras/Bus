<?php

namespace App\Domain\Fleet\Enums;

use App\Support\Concerns\HasLabel;

enum BusStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Idle = 'idle';
    case Maintenance = 'maintenance';
    case OutOfService = 'out_of_service';
    case Retired = 'retired';

    /** Only these statuses may host a new trip. */
    public function isDeployable(): bool
    {
        return in_array($this, [self::Active, self::Idle], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Idle => 'info',
            self::Maintenance => 'warning',
            self::OutOfService, self::Retired => 'danger',
        };
    }
}
