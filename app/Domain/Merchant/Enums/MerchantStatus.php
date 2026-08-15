<?php

namespace App\Domain\Merchant\Enums;

use App\Support\Concerns\HasLabel;

enum MerchantStatus: string
{
    use HasLabel;

    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function canAcceptPayments(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::PendingApproval => 'info',
            self::Suspended, self::Closed => 'danger',
        };
    }
}
