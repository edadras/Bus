<?php

namespace App\Support\Geo;

use App\Support\Exceptions\DomainException;

/**
 * Immutable WGS84 point. Validation happens once, at construction, so every
 * downstream calculation can assume a sane value.
 */
final readonly class Coordinate
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw DomainException::make('invalid_coordinate', 422, compact('lat', 'lng'));
        }
    }

    public static function make(float|string $lat, float|string $lng): self
    {
        return new self((float) $lat, (float) $lng);
    }

    /** @param array{lat?: float, lng?: float, 0?: float, 1?: float} $pair */
    public static function fromArray(array $pair): self
    {
        return new self(
            (float) ($pair['lat'] ?? $pair[0] ?? 0),
            (float) ($pair['lng'] ?? $pair[1] ?? 0),
        );
    }

    /** @return array{lat: float, lng: float} */
    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }

    public function equals(self $other, float $epsilon = 1e-7): bool
    {
        return abs($this->lat - $other->lat) < $epsilon && abs($this->lng - $other->lng) < $epsilon;
    }
}
