<?php

namespace App\Domain\Payment\Enums;

use App\Support\Concerns\HasLabel;

enum PaymentStatus: string
{
    use HasLabel;

    case Initiated = 'initiated';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::Refunded], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Initiated, self::Pending => 'warning',
            self::Failed, self::Cancelled => 'danger',
            self::Refunded => 'info',
        };
    }
}
