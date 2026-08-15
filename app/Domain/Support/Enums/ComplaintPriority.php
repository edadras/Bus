<?php

namespace App\Domain\Support\Enums;

use App\Support\Concerns\HasLabel;

enum ComplaintPriority: string
{
    use HasLabel;

    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function color(): string
    {
        return match ($this) {
            self::Urgent => 'danger',
            self::High => 'warning',
            self::Normal => 'info',
            self::Low => 'neutral',
        };
    }
}
