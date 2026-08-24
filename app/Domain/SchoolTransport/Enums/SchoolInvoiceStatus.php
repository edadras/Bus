<?php

namespace App\Domain\SchoolTransport\Enums;

use App\Support\Concerns\HasLabel;

enum SchoolInvoiceStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function isPayable(): bool
    {
        return in_array($this, [self::Pending, self::Overdue], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Pending => 'info',
            self::Overdue => 'danger',
            self::Cancelled => 'neutral',
        };
    }
}
