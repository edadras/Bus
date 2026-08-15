<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'bus_number' => $this->bus_number,
            'plate' => $this->plate,
            'model' => $this->model,
            'capacity_seated' => $this->capacity_seated,
            'capacity_standing' => $this->capacity_standing,
            'total_capacity' => $this->totalCapacity(),
            'has_air_conditioning' => $this->has_air_conditioning,
            'is_accessible' => $this->is_accessible,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'last_ping_at' => $this->last_ping_at?->toIso8601String(),
            'position' => $this->last_lat === null ? null : [
                'lat' => (float) $this->last_lat,
                'lng' => (float) $this->last_lng,
            ],
            'current_driver' => $this->whenLoaded('currentDriver', fn () => $this->currentDriver?->user?->name),
            'default_line' => new LineSummaryResource($this->whenLoaded('defaultLine')),
        ];
    }
}
