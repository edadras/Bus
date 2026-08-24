<?php

namespace App\Http\Resources\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxiLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'origin' => $this->origin_label,
            'destination' => $this->destination_label,
            'color' => $this->color,
            'flat_fare' => (int) $this->flat_fare,
            'formatted_fare' => Money::format((int) $this->flat_fare),
            'typical_duration_minutes' => $this->typical_duration_minutes,
            'is_active' => $this->is_active,
            'provenance' => $this->provenance->value,
            // Surfaced for the same reason it is on every stop: sample geometry
            // must never be presented as the published network.
            'is_verified_data' => $this->provenance->isVerified(),
            'origin_lat' => $this->origin_lat,
            'origin_lng' => $this->origin_lng,
            'destination_lat' => $this->destination_lat,
            'destination_lng' => $this->destination_lng,
        ];
    }
}
