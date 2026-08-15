<?php

namespace App\Domain\Merchant\Enums;

use App\Support\Concerns\HasLabel;

enum SettlementStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Requested = 'requested';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';

    public function color(): string
    {
        return match ($this) {
            self::Paid, self::Approved => 'success',
            self::Requested, self::Draft => 'warning',
            self::Rejected => 'danger',
        };
    }
}
