<?php

namespace App\Domain\Wallet\Enums;

use App\Support\Concerns\HasLabel;

/**
 * Ledger sign convention: every posting is written from the perspective of the
 * account it touches. A DEBIT reduces a wallet (money leaves), a CREDIT
 * increases it. The sum of (credit - debit) across all postings of a single
 * transaction must be exactly zero.
 */
enum LedgerDirection: string
{
    use HasLabel;

    case Debit = 'debit';
    case Credit = 'credit';

    public function signedAmount(int $amount): int
    {
        return $this === self::Credit ? $amount : -$amount;
    }

    public function opposite(): self
    {
        return $this === self::Credit ? self::Debit : self::Credit;
    }
}
