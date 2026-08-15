<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusStopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'address' => $this->address,
            'description' => $this->description,
            'is_terminal' => $this->is_terminal,
            'is_accessible' => $this->is_accessible,
            'has_shelter' => $this->has_shelter,
            'geofence_radius' => $this->geofence_radius,
            // Surfaced on every stop so no client can present demo geometry
            // as if it were the published network.
            'provenance' => $this->provenance->value,
            'is_verified_data' => $this->provenance->isVerified(),
            // Set by the "stops near me" query. Note this must not be called
            // `additional`: JsonResource declares its own $additional property,
            // which would shadow a model attribute of that name.
            'distance_meters' => $this->whenNotNull($this->resource->distance_meters ?? null),
            'lines' => LineSummaryResource::collection($this->whenLoaded('lines')),
        ];
    }
}
