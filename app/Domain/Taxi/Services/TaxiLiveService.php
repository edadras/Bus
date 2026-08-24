<?php

namespace App\Domain\Taxi\Services;

use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Models\TaxiShift;
use App\Support\Geo\Coordinate;
use App\Support\Live\LiveVehicleStore;

/**
 * What a taxi looks like on a map, and to whom.
 *
 * There are two audiences and they are given different things on purpose. A
 * passenger asking "what is near me" gets the position, the mode and how far
 * away it is — enough to walk towards a car. The operations room gets the
 * plate, the driver and who is aboard, because dispatching a city needs that
 * and a passenger does not.
 */
class TaxiLiveService
{
    private LiveVehicleStore $store;

    public function __construct()
    {
        $this->store = new LiveVehicleStore('taxi', (int) config('taxi.live.ttl_seconds', 120));
    }

    /** Publish the current state of a shift's car. */
    public function publish(TaxiShift $shift, ?Coordinate $at = null, ?float $speedKmh = null): void
    {
        $taxi = $shift->taxi;
        $position = $at ?? $taxi->lastPosition();

        if ($position === null) {
            return;
        }

        $this->store->put($shift->city_id, $taxi->id, [
            'uuid' => $taxi->uuid,
            'lat' => $position->lat,
            'lng' => $position->lng,
            'speed_kmh' => $speedKmh,
            'service_type' => $shift->service_type->value,
            'shift_id' => $shift->id,
            'shift_uuid' => $shift->uuid,
            'line_code' => $shift->line?->code,
            'line_name' => $shift->line?->name,
            // Only meaningful for a line taxi; a charter or a meter is either
            // free or it is not, which is the `is_available` flag below.
            'seats_free' => max(0, ($taxi->capacity ?? 4) - $shift->onboard_count),
            'is_available' => $this->isAvailable($shift, $taxi->capacity ?? 4),
            'onboard_count' => $shift->onboard_count,
            'driver_id' => $shift->driver_id,
            'taxi_number' => $taxi->taxi_number,
            'plate' => $taxi->plate,
            'color' => $taxi->color,
        ]);
    }

    public function forget(TaxiShift $shift): void
    {
        $this->store->forget($shift->city_id, $shift->taxi_id);
    }

    /**
     * The passenger-facing view: near me, and nothing that identifies anyone.
     *
     * No plate, no driver, no passenger count. A rider needs to know a car is
     * there and what it is offering; publishing who is driving it, to anyone
     * who asks, would be a tracking feed for taxi drivers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function nearby(int $cityId, Coordinate $centre, int $radiusMeters, ?string $mode = null): array
    {
        $radius = min($radiusMeters, (int) config('taxi.live.public_max_radius_meters', 3000));
        $limit = (int) config('taxi.live.public_max_results', 60);

        $found = $this->store->near($cityId, $centre, $radius, $limit);

        if ($mode !== null) {
            $found = array_values(array_filter(
                $found,
                static fn (array $state) => $state['service_type'] === $mode,
            ));
        }

        return array_map(static fn (array $state) => [
            'uuid' => $state['uuid'],
            'lat' => $state['lat'],
            'lng' => $state['lng'],
            'service_type' => $state['service_type'],
            'line_code' => $state['line_code'] ?? null,
            'line_name' => $state['line_name'] ?? null,
            'seats_free' => $state['seats_free'] ?? null,
            'is_available' => $state['is_available'] ?? true,
            'distance_meters' => $state['distance_meters'] ?? null,
            'reported_at' => $state['reported_at'] ?? null,
        ], $found);
    }

    /**
     * The operations view: every car in the city with the detail a dispatcher
     * actually uses. Gated on `operations.live_map` at the route.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forCity(int $cityId): array
    {
        return $this->store->forCity($cityId);
    }

    public function countForCity(int $cityId): int
    {
        return $this->store->countForCity($cityId);
    }

    /**
     * A shared line taxi takes anyone while a seat is free. A charter or a
     * metered car takes one hire at a time, so it is available only when empty.
     */
    private function isAvailable(TaxiShift $shift, int $capacity): bool
    {
        return $shift->service_type === TaxiServiceType::Line
            ? $shift->onboard_count < $capacity
            : $shift->onboard_count === 0;
    }
}
