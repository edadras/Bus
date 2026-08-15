<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Analytics\Services\DashboardService;
use App\Domain\Operations\Services\LiveStateStore;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly LiveStateStore $liveState,
    ) {}

    public function kpis(): JsonResponse
    {
        return ApiResponse::success($this->dashboard->kpis($this->city()));
    }

    public function charts(Request $request): JsonResponse
    {
        $range = $request->string('range', 'week')->toString();

        return ApiResponse::success([
            'range' => $range,
            'series' => $this->dashboard->series($this->city(), $range),
            'lines' => $this->dashboard->lineRanking($this->city(), $range),
            'stops' => $this->dashboard->stopRanking($this->city(), $range),
        ]);
    }

    /**
     * The operations room's live fleet view. Richer than the public feed —
     * it includes driver and occupancy detail — which is why it sits behind
     * the operations.live_map permission.
     */
    public function liveMap(Request $request): JsonResponse
    {
        $buses = $this->liveState->forCity($this->city()->id);

        $tripIds = array_column($buses, 'trip_id');

        $crews = \App\Domain\Operations\Models\Trip::whereIn('id', $tripIds)
            ->with(['driver.user:id,first_name,last_name,display_name,mobile'])
            ->get()
            ->keyBy('id');

        $enriched = array_map(function (array $bus) use ($crews) {
            $trip = $crews->get($bus['trip_id']);

            return $bus + [
                'driver_name' => $trip?->driver?->user?->name,
                'driver_uuid' => $trip?->driver?->uuid,
                'started_at' => $trip?->started_at?->toIso8601String(),
                'distance_meters' => $trip?->distance_meters,
            ];
        }, $buses);

        return ApiResponse::success($enriched, [
            'count' => count($enriched),
            'channel' => config('transit.live.channel_prefix').'.city.'.$this->city()->id.'.buses',
        ]);
    }
}
