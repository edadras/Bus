<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'shift' => $this->shift,
            'capacity' => $this->capacity,
            'days_of_week' => $this->days_of_week,
            'pickup_starts_at' => $this->pickup_starts_at,
            'dropoff_starts_at' => $this->dropoff_starts_at,
            'is_active' => $this->is_active,
            'notes' => $this->notes,

            'seats_taken' => $this->resource->seatsTaken(),
            'seats_free' => $this->resource->seatsFree(),
            // What the van is missing before it can go out, as an answer rather
            // than four fields the panel has to reason about.
            'readiness_blocker' => $this->resource->readinessBlocker(),

            'school' => $this->whenLoaded('school', fn () => $this->school === null ? null : [
                'uuid' => $this->school->uuid,
                'name' => $this->school->name,
            ]),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle === null ? null : [
                'uuid' => $this->vehicle->uuid,
                'plate' => $this->vehicle->plate,
                'capacity' => $this->vehicle->capacity,
                'insurance_expires_at' => $this->vehicle->insurance_expires_at?->toDateString(),
            ]),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver === null ? null : [
                'uuid' => $this->driver->uuid,
                'name' => $this->driver->user?->name,
            ]),
            'company' => $this->whenLoaded('company', fn () => [
                'uuid' => $this->company->uuid,
                'name' => $this->company->name,
            ]),
        ];
    }
}
