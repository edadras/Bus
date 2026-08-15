<?php

namespace App\Domain\Operations\DTO;

/**
 * A predicted arrival, carrying its own provenance. `confidence` and `source`
 * exist so the UI can show "۴ دقیقه" firmly or "حدود ۴ دقیقه" softly instead
 * of pretending every estimate is equally good.
 */
final readonly class EtaEstimate
{
    public function __construct(
        public int $seconds,
        public float $confidence,
        public string $source,
        public float $distanceMeters,
        public int $stopsAway,
        public array $components = [],
    ) {}

    public function minutes(): int
    {
        return (int) max(1, round($this->seconds / 60));
    }

    public function arrivesAt(): \Carbon\CarbonInterface
    {
        return now()->addSeconds($this->seconds);
    }

    public function isReliable(): bool
    {
        return $this->confidence >= 0.6;
    }

    public function toArray(): array
    {
        return [
            'seconds' => $this->seconds,
            'minutes' => $this->minutes(),
            'arrives_at' => $this->arrivesAt()->toIso8601String(),
            'confidence' => round($this->confidence, 2),
            'source' => $this->source,
            'distance_meters' => (int) round($this->distanceMeters),
            'stops_away' => $this->stopsAway,
            'reliable' => $this->isReliable(),
        ];
    }
}
