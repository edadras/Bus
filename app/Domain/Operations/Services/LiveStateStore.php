<?php

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Models\Trip;
use Illuminate\Support\Facades\Cache;

/**
 * Hot, ephemeral view of every moving bus.
 *
 * Writing each ping to MySQL and reading the live map from it would make the
 * map's cost scale with fleet size times viewers. Instead the current state of
 * each trip lives in a short-TTL cache entry, and the city index is a plain
 * set of trip ids. MySQL keeps the durable history; this keeps the map cheap.
 *
 * The store degrades gracefully: with the array/file cache driver (tests, a
 * bare local install) it still works, just without Redis' set operations.
 */
class LiveStateStore
{
    public function put(Trip $trip, array $state): void
    {
        $ttl = (int) config('transit.live.bus_ttl', 180);

        Cache::put($this->tripKey($trip->id), $state, $ttl);
        $this->indexTrip($trip->city_id, $trip->id, $ttl);
    }

    public function get(int $tripId): ?array
    {
        return Cache::get($this->tripKey($tripId));
    }

    public function forget(Trip $trip): void
    {
        Cache::forget($this->tripKey($trip->id));
        $this->deindexTrip($trip->city_id, $trip->id);
    }

    /** @return array<int, array<string, mixed>> live state for one city */
    public function forCity(int $cityId): array
    {
        $ids = $this->indexedTripIds($cityId);

        if ($ids === []) {
            return [];
        }

        $keys = array_map(fn (int $id) => $this->tripKey($id), $ids);
        $states = Cache::many($keys);

        $live = [];
        $stale = [];

        foreach ($ids as $index => $id) {
            $state = $states[$keys[$index]] ?? null;

            if ($state === null) {
                // The entry expired: the bus stopped reporting. Drop it from
                // the index so it does not accumulate forever.
                $stale[] = $id;

                continue;
            }

            $live[] = $state;
        }

        foreach ($stale as $id) {
            $this->deindexTrip($cityId, $id);
        }

        return $live;
    }

    public function countForCity(int $cityId): int
    {
        return count($this->forCity($cityId));
    }

    private function indexTrip(int $cityId, int $tripId, int $ttl): void
    {
        $key = $this->indexKey($cityId);
        $ids = Cache::get($key, []);

        if (! in_array($tripId, $ids, true)) {
            $ids[] = $tripId;
        }

        // The index outlives individual entries so a briefly silent bus is not
        // lost, but it is still bounded so an abandoned city drains on its own.
        Cache::put($key, $ids, $ttl * 4);
    }

    private function deindexTrip(int $cityId, int $tripId): void
    {
        $key = $this->indexKey($cityId);
        $ids = array_values(array_filter(Cache::get($key, []), fn (int $id) => $id !== $tripId));

        Cache::put($key, $ids, (int) config('transit.live.bus_ttl', 180) * 4);
    }

    /** @return array<int, int> */
    private function indexedTripIds(int $cityId): array
    {
        return array_values(Cache::get($this->indexKey($cityId), []));
    }

    private function tripKey(int $tripId): string
    {
        return config('transit.live.key_prefix', 'live').":trip:$tripId";
    }

    private function indexKey(int $cityId): string
    {
        return config('transit.live.key_prefix', 'live').":city:$cityId:trips";
    }
}
