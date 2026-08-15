<?php

namespace App\Domain\Operations\DTO;

use App\Support\Geo\Coordinate;
use Carbon\CarbonImmutable;

/** A single GPS sample as reported by the driver app. */
final readonly class LocationPing
{
    public function __construct(
        public Coordinate $coordinate,
        public ?float $speedKmh,
        public ?float $heading,
        public ?float $accuracyMeters,
        public ?float $altitude,
        public CarbonImmutable $recordedAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            coordinate: new Coordinate((float) $data['lat'], (float) $data['lng']),
            speedKmh: isset($data['speed']) ? (float) $data['speed'] : null,
            heading: isset($data['heading']) ? (float) $data['heading'] : null,
            accuracyMeters: isset($data['accuracy']) ? (float) $data['accuracy'] : null,
            altitude: isset($data['altitude']) ? (float) $data['altitude'] : null,
            recordedAt: isset($data['recorded_at'])
                ? CarbonImmutable::parse($data['recorded_at'])
                : CarbonImmutable::now(),
        );
    }
}
