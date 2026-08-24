<?php

namespace App\Domain\SchoolTransport\Enums;

use App\Support\Concerns\HasLabel;

/**
 * Where one child is on one run.
 *
 * `Pending` exists so that a child nobody touched is visibly unaccounted for
 * rather than quietly absent from the data. At the end of a run, every row that
 * is still pending is a question somebody has to answer.
 */
enum SchoolAttendanceStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case PickedUp = 'picked_up';
    case DroppedOff = 'dropped_off';
    case Absent = 'absent';
    case NoShow = 'no_show';

    public function isAboard(): bool
    {
        return $this === self::PickedUp;
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::DroppedOff, self::Absent, self::NoShow], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::DroppedOff => 'success',
            self::PickedUp => 'info',
            self::Pending => 'warning',
            self::Absent, self::NoShow => 'neutral',
        };
    }
}
