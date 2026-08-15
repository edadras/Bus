<?php

namespace App\Domain\Wallet\Enums;

use App\Support\Concerns\HasLabel;

enum TransactionStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Pending => 'warning',
            self::Failed => 'danger',
            self::Reversed => 'neutral',
        };
    }
}
