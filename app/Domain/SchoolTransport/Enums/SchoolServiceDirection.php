<?php

namespace App\Domain\SchoolTransport\Enums;

use App\Support\Concerns\HasLabel;

/**
 * Which way a run goes.
 *
 * The two directions are mirror images in the one way that matters to a driver:
 * going to school the children are collected from many doors and set down at
 * one, and coming home it is the other way round. `Both` is only ever a
 * contract or route setting, never a run.
 */
enum SchoolServiceDirection: string
{
    use HasLabel;

    case ToSchool = 'to_school';
    case FromSchool = 'from_school';
    case Both = 'both';

    /** True when children are collected from their own doors on this run. */
    public function collectsFromHomes(): bool
    {
        return $this === self::ToSchool;
    }

    /** @return array<int, self> the runs a setting produces each day */
    public function runs(): array
    {
        return $this === self::Both
            ? [self::ToSchool, self::FromSchool]
            : [$this];
    }

    public function color(): string
    {
        return 'info';
    }
}
