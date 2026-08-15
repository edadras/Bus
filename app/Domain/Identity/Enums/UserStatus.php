<?php

namespace App\Domain\Identity\Enums;

use App\Support\Concerns\HasLabel;

enum UserStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
