<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One run, as the driver app and the company panel read it. */
class SchoolTripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'service_date' => $this->service_date?->toDateString(),
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'is_live' => $this->status->allowsLiveTracking(),

            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),

            'expected_count' => $this->expected_count,
            'picked_up_count' => $this->picked_up_count,
            'dropped_off_count' => $this->dropped_off_count,
            'absent_count' => $this->absent_count,

            'route' => $this->whenLoaded('route', fn () => [
                'uuid' => $this->route->uuid,
                'name' => $this->route->name,
                'pickup_starts_at' => $this->route->pickup_starts_at,
                'dropoff_starts_at' => $this->route->dropoff_starts_at,
                'school' => $this->route->school === null ? null : [
                    'uuid' => $this->route->school->uuid,
                    'name' => $this->route->school->name,
                    'address' => $this->route->school->address,
                    'lat' => $this->route->school->lat,
                    'lng' => $this->route->school->lng,
                ],
            ]),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle === null ? null : [
                'uuid' => $this->vehicle->uuid,
                'plate' => $this->vehicle->plate,
                'model' => $this->vehicle->model,
                'capacity' => $this->vehicle->capacity,
            ]),
            'students' => SchoolTripStudentResource::collection($this->whenLoaded('students')),
        ];
    }
}
