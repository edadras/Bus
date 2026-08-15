<?php

namespace App\Domain\Fleet\Enums;

use App\Support\Concerns\HasLabel;

enum ShiftStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Closed = 'closed';
    case ForceClosed = 'force_closed';
}
