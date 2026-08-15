<?php

namespace App\Domain\Support\Enums;

use App\Support\Concerns\HasLabel;

enum ComplaintStatus: string
{
    use HasLabel;

    case New = 'new';
    case Reviewing = 'reviewing';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Reviewing, self::InProgress], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Reviewing, self::InProgress => 'warning',
            self::Resolved => 'success',
            self::Closed => 'neutral',
        };
    }
}
