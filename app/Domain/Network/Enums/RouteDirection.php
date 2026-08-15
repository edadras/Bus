<?php

namespace App\Domain\Network\Enums;

use App\Support\Concerns\HasLabel;

enum RouteDirection: string
{
    use HasLabel;

    case Outbound = 'outbound';
    case Inbound = 'inbound';
    case Loop = 'loop';

    public function opposite(): self
    {
        return match ($this) {
            self::Outbound => self::Inbound,
            self::Inbound => self::Outbound,
            self::Loop => self::Loop,
        };
    }
}
