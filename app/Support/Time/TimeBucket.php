<?php

namespace App\Support\Time;

use Carbon\CarbonInterface;

/**
 * Historical travel times are aggregated into (day type, hour) buckets. Using
 * a coarse bucket keeps the statistics dense enough to be meaningful on a
 * network that only sees a few hundred trips a day.
 */
final class TimeBucket
{
    /** Iran's weekend is Thursday afternoon + Friday; Friday is the day off. */
    public const DAY_TYPE_WEEKDAY = 'weekday';

    public const DAY_TYPE_WEEKEND = 'weekend';

    public static function dayType(CarbonInterface $at): string
    {
        // Carbon: 5 = Friday. Thursday (4) is treated as a weekday for transit
        // demand because services run a normal schedule.
        return $at->dayOfWeek === CarbonInterface::FRIDAY
            ? self::DAY_TYPE_WEEKEND
            : self::DAY_TYPE_WEEKDAY;
    }

    public static function hour(CarbonInterface $at): int
    {
        return (int) $at->format('G');
    }

    /** @return array{day_type: string, hour_bucket: int} */
    public static function of(CarbonInterface $at): array
    {
        return [
            'day_type' => self::dayType($at),
            'hour_bucket' => self::hour($at),
        ];
    }

    public static function key(CarbonInterface $at): string
    {
        return self::dayType($at).':'.self::hour($at);
    }
}
