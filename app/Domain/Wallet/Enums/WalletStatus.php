<?php

namespace App\Domain\Wallet\Enums;

use App\Support\Concerns\HasLabel;

enum WalletStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Frozen = 'frozen';
    case Closed = 'closed';

    public function canDebit(): bool
    {
        return $this === self::Active;
    }

    public function canCredit(): bool
    {
        return $this !== self::Closed;
    }
}
