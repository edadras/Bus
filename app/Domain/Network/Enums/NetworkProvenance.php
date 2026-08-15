<?php

namespace App\Domain\Network\Enums;

use App\Support\Concerns\HasLabel;

/**
 * Where a piece of network data came from. Anything not marked `official`
 * must be visibly labelled in every client so demo geometry is never
 * mistaken for the published network.
 */
enum NetworkProvenance: string
{
    use HasLabel;

    case Official = 'official';
    case Community = 'community';
    case Sample = 'sample';

    public function isVerified(): bool
    {
        return $this === self::Official;
    }

    public function color(): string
    {
        return match ($this) {
            self::Official => 'success',
            self::Community => 'info',
            self::Sample => 'warning',
        };
    }
}
