<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'position' => $this->current_lat === null ? null : [
                'lat' => (float) $this->current_lat,
                'lng' => (float) $this->current_lng,
                'speed_kmh' => $this->current_speed_kmh,
                'heading' => $this->current_heading,
                'updated_at' => $this->last_ping_at?->toIso8601String(),
                'stale' => $this->isStale(),
            ],
            'bus' => [
                'uuid' => $this->bus?->uuid,
                'number' => $this->bus?->bus_number,
                'capacity' => $this->bus?->totalCapacity(),
                'is_accessible' => $this->bus?->is_accessible,
                'has_air_conditioning' => $this->bus?->has_air_conditioning,
            ],
            'line' => new LineSummaryResource($this->whenLoaded('line')),
            'origin' => $this->originStop?->name,
            'destination' => $this->destinationStop?->name,
            'current_stop' => $this->currentStop?->name,
            'next_stop' => $this->nextStop === null ? null : [
                'id' => $this->nextStop->id,
                'name' => $this->nextStop->name,
                'distance_meters' => $this->distance_to_next_stop,
                'eta_seconds' => $this->eta_next_stop_seconds,
            ],
            'passenger_count' => $this->passenger_count,
            'occupancy' => $this->occupancyRatio(),
            'distance_meters' => $this->distance_meters,
            'is_off_route' => $this->is_off_route,
            'is_idle' => $this->is_idle,
        ];
    }
}
