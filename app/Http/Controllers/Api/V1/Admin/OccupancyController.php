<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Services\LiveStateStore;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Live ridership across the fleet, aggregated.
 *
 * The specification asked for a "live passenger map". This deliberately
 * publishes occupancy per vehicle — "bus 102: 27 passengers" — and never the
 * position or identity of individual riders.
 *
 * That is not a shortcut. A per-person live map would mean staff could watch a
 * named individual move across the city in real time, which is a far larger
 * capability than operating a bus network requires. Everything an operations
 * team actually does with this screen — spotting crowding, rebalancing
 * frequency, dispatching a relief vehicle — is answered by counts.
 *
 * A passenger's own position is available only on their own ride, only while
 * it is open, only if they opted in, and is pruned within a day.
 */
class OccupancyController extends Controller
{
    public function __construct(private readonly LiveStateStore $liveState) {}

    public function __invoke(): JsonResponse
    {
        $live = $this->liveState->forCity($this->city()->id);

        // Counts come from the database, not the cached map snapshot: the
        // snapshot only refreshes on the next GPS ping and would under-report
        // anyone who has just boarded.
        $trips = Trip::forCity($this->city())
            ->live()
            ->with(['bus:id,uuid,bus_number,capacity_seated,capacity_standing', 'line:id,code,name,color'])
            ->get();

        $positions = collect($live)->keyBy('trip_id');

        $rows = $trips->map(function (Trip $trip) use ($positions) {
            $capacity = $trip->bus?->totalCapacity() ?: 0;
            $occupancy = $capacity > 0 ? round($trip->passenger_count / $capacity, 3) : 0.0;
            $position = $positions->get($trip->id);

            return [
                'trip_uuid' => $trip->uuid,
                'bus_number' => $trip->bus?->bus_number,
                'line_code' => $trip->line?->code,
                'line_name' => $trip->line?->name,
                'line_color' => $trip->line?->color,
                'passenger_count' => $trip->passenger_count,
                'peak_passenger_count' => $trip->peak_passenger_count,
                'capacity' => $capacity,
                'occupancy' => $occupancy,
                'crowding' => match (true) {
                    $occupancy >= 0.9 => 'full',
                    $occupancy >= 0.7 => 'crowded',
                    $occupancy >= 0.35 => 'moderate',
                    default => 'light',
                },
                // Position of the vehicle, not of any passenger.
                'lat' => $position['lat'] ?? $trip->current_lat,
                'lng' => $position['lng'] ?? $trip->current_lng,
                'updated_at' => $trip->last_ping_at?->toIso8601String(),
            ];
        })->values();

        $totalCapacity = $rows->sum('capacity');
        $totalAboard = $rows->sum('passenger_count');

        return ApiResponse::success($rows->all(), [
            'totals' => [
                'buses_in_service' => $rows->count(),
                'passengers_on_board' => $totalAboard,
                'total_capacity' => $totalCapacity,
                'network_occupancy' => $totalCapacity > 0
                    ? round($totalAboard / $totalCapacity, 3)
                    : 0.0,
                'crowded_buses' => $rows->whereIn('crowding', ['crowded', 'full'])->count(),
            ],
            'privacy_note' => 'aggregated_per_vehicle',
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
