<?php

namespace App\Domain\Wallet\Enums;

use App\Support\Concerns\HasLabel;

enum WalletOwnerType: string
{
    use HasLabel;

    case User = 'user';
    case Merchant = 'merchant';
    case System = 'system';
}
