<?php

namespace App\Domain\SchoolTransport\Enums;

use App\Support\Concerns\HasLabel;

enum SchoolCompanyStatus: string
{
    use HasLabel;

    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    /** Only an approved company is ever shown to a parent choosing one. */
    public function isVisibleToGuardians(): bool
    {
        return $this === self::Active;
    }

    public function canOperate(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::PendingApproval => 'warning',
            self::Suspended, self::Rejected => 'danger',
        };
    }
}
