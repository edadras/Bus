<?php

namespace App\Support;

use App\Support\Exceptions\DomainException;

/**
 * Money is always an integer count of minor units. This class exists to make
 * that contract explicit and to centralise formatting for the Persian UI,
 * which conventionally displays Toman (1 Toman = 10 Rial).
 */
final class Money
{
    public static function assertPositive(int $amount): void
    {
        if ($amount <= 0) {
            throw DomainException::make('amount_must_be_positive', 422, ['amount' => $amount]);
        }
    }

    public static function currency(): string
    {
        return (string) config('wallet.currency', 'IRR');
    }

    /** Format minor units for display, honouring the configured display unit. */
    public static function format(int $minorUnits, bool $withSuffix = true): string
    {
        $value = config('wallet.display_unit') === 'toman'
            ? intdiv($minorUnits, 10)
            : $minorUnits;

        // Persian pages set numbers in Persian digits; the browser already
        // does this, and the server must agree with it on the same page.
        $formatted = Digits::number($value);

        if (! $withSuffix) {
            return $formatted;
        }

        return $formatted.' '.__('common.currency_'.config('wallet.display_unit'));
    }

    /** Basis-point share of an amount, rounded half up. */
    public static function bps(int $amount, int $basisPoints): int
    {
        return (int) round($amount * $basisPoints / 10_000);
    }
}
