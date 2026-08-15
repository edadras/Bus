<?php

namespace App\Support\Geo;

/**
 * An ordered list of coordinates with cumulative distances precomputed, so
 * "how far along the route is this bus" is an O(n) scan rather than repeated
 * trigonometry. Route geometry is loaded once and cached per request.
 */
final class Polyline
{
    /** @var array<int, Coordinate> */
    private array $points;

    /** @var array<int, float> cumulative metres at each vertex */
    private array $cumulative = [];

    private float $length = 0.0;

    /** @param array<int, Coordinate> $points */
    public function __construct(array $points)
    {
        $this->points = array_values($points);

        $total = 0.0;
        $this->cumulative[0] = 0.0;

        for ($i = 1, $n = count($this->points); $i < $n; $i++) {
            $total += Distance::between($this->points[$i - 1], $this->points[$i]);
            $this->cumulative[$i] = $total;
        }

        $this->length = $total;
    }

    /** @param array<int, array{lat: float, lng: float}|array{0: float, 1: float}> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(array_map(Coordinate::fromArray(...), $raw));
    }

    /** @return array<int, Coordinate> */
    public function points(): array
    {
        return $this->points;
    }

    public function isEmpty(): bool
    {
        return count($this->points) < 2;
    }

    public function length(): float
    {
        return $this->length;
    }

    /**
     * Snap a point onto the polyline.
     *
     * @return array{distance: float, offset: float, index: int, point: Coordinate}
     *                                                                              distance = metres off the line, offset = metres travelled along it
     */
    public function snap(Coordinate $point): array
    {
        if ($this->isEmpty()) {
            return ['distance' => INF, 'offset' => 0.0, 'index' => 0, 'point' => $point];
        }

        $best = ['distance' => INF, 'offset' => 0.0, 'index' => 0, 'point' => $this->points[0]];

        for ($i = 1, $n = count($this->points); $i < $n; $i++) {
            $result = Distance::toSegment($point, $this->points[$i - 1], $this->points[$i]);

            if ($result['distance'] < $best['distance']) {
                $segmentLength = $this->cumulative[$i] - $this->cumulative[$i - 1];

                $best = [
                    'distance' => $result['distance'],
                    'offset' => $this->cumulative[$i - 1] + $segmentLength * $result['t'],
                    'index' => $i - 1,
                    'point' => $result['point'],
                ];
            }
        }

        return $best;
    }

    /** Distance in metres between two snapped offsets, forward along the line. */
    public function distanceBetweenOffsets(float $from, float $to): float
    {
        return max(0.0, $to - $from);
    }

    /** @return array<int, array{lat: float, lng: float}> */
    public function toArray(): array
    {
        return array_map(static fn (Coordinate $c) => $c->toArray(), $this->points);
    }
}
