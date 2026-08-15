<?php

namespace App\Support;

/**
 * Locale-appropriate numerals.
 *
 * Persian text sets numbers in Persian digits and separates thousands with the
 * Arabic thousands separator (U+066C), not a comma. The browser already does
 * this through `Intl.NumberFormat('fa-IR')`; without this helper the same
 * amount rendered by the server would appear in Latin digits on the same page,
 * which reads as two different systems talking.
 *
 * Only display is converted. Nothing here is ever applied to a value on its way
 * into the database or into a signature.
 */
final class Digits
{
    private const PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const PERSIAN_THOUSANDS = '٬';

    /** Convert Latin digits in a string to the current locale's numerals. */
    public static function localise(string $value, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if ($locale !== 'fa') {
            return $value;
        }

        return str_replace(
            [...range('0', '9'), ','],
            [...self::PERSIAN, self::PERSIAN_THOUSANDS],
            $value,
        );
    }

    /** Format an integer with thousands separators, in the locale's numerals. */
    public static function number(int|float $value, int $decimals = 0): string
    {
        return self::localise(number_format($value, $decimals));
    }
}
