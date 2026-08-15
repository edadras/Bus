<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'distance_meters' => $this->distance_meters,
            'typical_duration_minutes' => $this->typical_duration_minutes,
            'origin' => new BusStopResource($this->whenLoaded('originStop')),
            'destination' => new BusStopResource($this->whenLoaded('destinationStop')),
            // Geometry is heavy; only sent when the caller asked for the detail.
            'geometry' => $this->when($request->boolean('with_geometry'), fn () => $this->polyline()->toArray()),
            'stops' => RouteStopResource::collection($this->whenLoaded('routeStops')),
            'provenance' => $this->provenance->value,
        ];
    }
}
