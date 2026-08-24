<?php

namespace App\Support\Live;

use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use Illuminate\Support\Facades\Cache;

/**
 * Hot, ephemeral view of a fleet of moving vehicles.
 *
 * The bus map has had one of these since the beginning; taxis and school
 * vehicles need the same thing and there is no reason for three copies. The
 * store is parameterised by a kind, so each fleet gets its own key space and
 * its own city index while sharing the eviction and the "near me" query.
 *
 * Durable history stays in MySQL. This is only what is moving right now, and
 * it is allowed to forget: a vehicle that stops reporting drops off the map on
 * its own rather than hanging there as a ghost.
 */
class LiveVehicleStore
{
    public function __construct(
        private readonly string $kind,
        private readonly int $ttlSeconds,
    ) {}

    /** @param array<string, mixed> $state must carry lat and lng */
    public function put(int $cityId, int $vehicleId, array $state): void
    {
        Cache::put($this->vehicleKey($vehicleId), $state + [
            'id' => $vehicleId,
            'reported_at' => now()->toIso8601String(),
        ], $this->ttlSeconds);

        $this->index($cityId, $vehicleId);
    }

    /** @return array<string, mixed>|null */
    public function get(int $vehicleId): ?array
    {
        return Cache::get($this->vehicleKey($vehicleId));
    }

    public function forget(int $cityId, int $vehicleId): void
    {
        Cache::forget($this->vehicleKey($vehicleId));
        $this->deindex($cityId, $vehicleId);
    }

    /** @return array<int, array<string, mixed>> everything moving in one city */
    public function forCity(int $cityId): array
    {
        $ids = array_values(Cache::get($this->indexKey($cityId), []));

        if ($ids === []) {
            return [];
        }

        $keys = array_map(fn (int $id) => $this->vehicleKey($id), $ids);
        $states = Cache::many($keys);

        $live = [];
        $stale = [];

        foreach ($ids as $index => $id) {
            $state = $states[$keys[$index]] ?? null;

            if ($state === null) {
                $stale[] = $id;

                continue;
            }

            $live[] = $state;
        }

        foreach ($stale as $id) {
            $this->deindex($cityId, $id);
        }

        return $live;
    }

    /**
     * Everything within a radius, nearest first, with the distance attached.
     *
     * This is what a passenger's "taxis near me" is served from, and the radius
     * is the caller's to cap — the store will happily answer a large one, so
     * the endpoint above it must not.
     *
     * @return array<int, array<string, mixed>>
     */
    public function near(int $cityId, Coordinate $centre, int $radiusMeters, int $limit = 50): array
    {
        $found = [];

        foreach ($this->forCity($cityId) as $state) {
            if (! isset($state['lat'], $state['lng'])) {
                continue;
            }

            $distance = Distance::between(
                $centre,
                new Coordinate((float) $state['lat'], (float) $state['lng']),
            );

            if ($distance > $radiusMeters) {
                continue;
            }

            $state['distance_meters'] = (int) round($distance);
            $found[] = $state;
        }

        usort($found, static fn (array $a, array $b) => $a['distance_meters'] <=> $b['distance_meters']);

        return array_slice($found, 0, max(1, $limit));
    }

    public function countForCity(int $cityId): int
    {
        return count($this->forCity($cityId));
    }

    private function index(int $cityId, int $vehicleId): void
    {
        $key = $this->indexKey($cityId);
        $ids = Cache::get($key, []);

        if (! in_array($vehicleId, $ids, true)) {
            $ids[] = $vehicleId;
        }

        // The index outlives the entries so a briefly silent vehicle is not
        // lost, but it is still bounded so an abandoned city drains on its own.
        Cache::put($key, $ids, $this->ttlSeconds * 4);
    }

    private function deindex(int $cityId, int $vehicleId): void
    {
        $key = $this->indexKey($cityId);
        $ids = array_values(array_filter(
            Cache::get($key, []),
            static fn (int $id) => $id !== $vehicleId,
        ));

        Cache::put($key, $ids, $this->ttlSeconds * 4);
    }

    private function vehicleKey(int $vehicleId): string
    {
        return $this->prefix().":{$this->kind}:$vehicleId";
    }

    private function indexKey(int $cityId): string
    {
        return $this->prefix().":city:$cityId:{$this->kind}s";
    }

    private function prefix(): string
    {
        return (string) config('transit.live.key_prefix', 'live');
    }
}
