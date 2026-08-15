<?php

namespace App\Domain\Mapping\Providers;

use App\Domain\Mapping\Contracts\MapProvider;

/**
 * Tile-only provider covering OpenStreetMap, Mapbox, Google and Neshan raster
 * endpoints. Routing and geocoding are not implemented here — the platform's
 * own route geometry is authoritative, and refusing rather than faking keeps
 * callers honest about what is actually available.
 */
class TileMapProvider implements MapProvider
{
    public function __construct(private readonly string $provider) {}

    public function name(): string
    {
        return $this->provider;
    }

    public function tileConfig(): array
    {
        $config = (array) config("transit.map.providers.{$this->provider}");

        return [
            'provider' => $this->provider,
            'tile_url' => (string) ($config['tile_url'] ?? config('transit.map.providers.osm.tile_url')),
            'attribution' => (string) ($config['attribution'] ?? ''),
            'max_zoom' => (int) ($config['max_zoom'] ?? 19),
            'token' => $config['token'] ?? null,
        ];
    }

    public function supportsRouting(): bool
    {
        return false;
    }

    public function supportsGeocoding(): bool
    {
        return false;
    }

    public function route(array $waypoints): ?array
    {
        return null;
    }

    public function geocode(string $query, ?array $near = null): array
    {
        return [];
    }
}
