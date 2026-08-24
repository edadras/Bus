<?php

namespace App\Domain\Taxi\Enums;

use App\Support\Concerns\HasLabel;

/**
 * The three ways a taxi earns.
 *
 * These are not variations on one idea, they are three different products with
 * three different risks, and the code keeps them apart deliberately:
 *
 *   Line     a published route at a published flat fare. The passenger knows
 *            the price before scanning; the only question is whether they are
 *            charged once.
 *   Charter  the driver names a price. The danger is a passenger being charged
 *            an amount they never saw, so a charter is always confirmed
 *            against a quote the passenger has read.
 *   Meter    the price is not known when the ride starts. The danger is a
 *            wallet that cannot cover the fare at the end, so a metered ride
 *            checks for enough balance before it starts running.
 */
enum TaxiServiceType: string
{
    use HasLabel;

    case Line = 'line';
    case Charter = 'charter';
    case Meter = 'meter';

    /** True when the fare is known before the passenger commits. */
    public function isPricedUpFront(): bool
    {
        return $this !== self::Meter;
    }

    /** True when the ride occupies a seat until somebody ends it. */
    public function isOpenEnded(): bool
    {
        return $this === self::Meter;
    }

    public function color(): string
    {
        return match ($this) {
            self::Line => 'success',
            self::Charter => 'warning',
            self::Meter => 'info',
        };
    }
}
