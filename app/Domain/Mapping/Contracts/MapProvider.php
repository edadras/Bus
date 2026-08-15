<?php

namespace App\Domain\Mapping\Contracts;

/**
 * Mapping is abstracted so the tile source can change without touching any
 * client. Providers supply tile configuration and, optionally, routing and
 * geocoding; callers must check the `supports*` methods before relying on them.
 */
interface MapProvider
{
    public function name(): string;

    /** @return array{tile_url: string, attribution: string, max_zoom: int, token: ?string} */
    public function tileConfig(): array;

    public function supportsRouting(): bool;

    public function supportsGeocoding(): bool;

    /**
     * @param  array<int, array{lat: float, lng: float}>  $waypoints
     * @return array{geometry: array<int, array{lat: float, lng: float}>, distance_meters: int, duration_seconds: int}|null
     */
    public function route(array $waypoints): ?array;

    /** @return array<int, array{name: string, lat: float, lng: float}> */
    public function geocode(string $query, ?array $near = null): array;
}
