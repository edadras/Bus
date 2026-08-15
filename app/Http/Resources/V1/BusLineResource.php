<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'color' => $this->color,
            'origin' => $this->origin_label,
            'destination' => $this->destination_label,
            'typical_duration_minutes' => $this->typical_duration_minutes,
            'headway_minutes' => $this->headway_minutes,
            'service_start' => $this->service_start,
            'service_end' => $this->service_end,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'provenance' => $this->provenance->value,
            'is_verified_data' => $this->provenance->isVerified(),
            'routes' => RouteResource::collection($this->whenLoaded('routes')),
            'active_bus_count' => $this->whenNotNull($this->additional['active_bus_count'] ?? null),
        ];
    }
}
