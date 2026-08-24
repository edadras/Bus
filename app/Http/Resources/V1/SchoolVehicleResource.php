<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolVehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'plate' => $this->plate,
            'model' => $this->model,
            'color' => $this->color,
            'manufacture_year' => $this->manufacture_year,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'has_supervisor' => $this->has_supervisor,
            'has_seatbelts' => $this->has_seatbelts,
            'has_air_conditioning' => $this->has_air_conditioning,
            'insurance_expires_at' => $this->insurance_expires_at?->toDateString(),
            'inspection_due_at' => $this->inspection_due_at?->toDateString(),
            // Paperwork that has to be in date for the van to be on the road,
            // answered rather than left for the panel to compare dates.
            'compliance_blocker' => $this->resource->complianceBlocker(),
            'notes' => $this->notes,
        ];
    }
}
