<?php

namespace App\Domain\Operations\DTO;

use App\Domain\Network\Models\RouteStop;

/** Where a ping sits relative to the route it is supposed to be following. */
final readonly class RouteMatch
{
    public function __construct(
        /** Metres travelled along the route geometry. */
        public float $offsetMeters,
        /** Perpendicular distance from the route polyline. */
        public float $deviationMeters,
        public bool $isOffRoute,
        public ?RouteStop $nextStop,
        public ?RouteStop $previousStop,
        public ?float $distanceToNextStop,
        /** Route stops passed since the previous ping. */
        public array $passedStops = [],
    ) {}
}
