<?php

namespace App\Http\Resources\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),

            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'days_of_week' => $this->days_of_week,

            'pickup_address' => $this->resource->pickupAddress(),
            'pickup_lat' => $this->pickup_lat,
            'pickup_lng' => $this->pickup_lng,

            'fee_amount' => $this->fee_amount,
            'payable_amount' => $this->resource->payableAmount(),
            'formatted_fee' => Money::format($this->resource->payableAmount()),
            'payment_cycle' => $this->payment_cycle,

            'guardian_note' => $this->guardian_note,
            'company_note' => $this->company_note,
            'rejection_reason' => $this->rejection_reason,

            'student' => $this->whenLoaded('student', fn () => (new SchoolStudentResource($this->student))->resolve()),
            'company' => $this->whenLoaded('company', fn () => [
                'uuid' => $this->company->uuid,
                'name' => $this->company->name,
                'phone' => $this->company->phone,
            ]),
            'school' => $this->whenLoaded('school', fn () => $this->school === null ? null : [
                'uuid' => $this->school->uuid,
                'name' => $this->school->name,
            ]),
            // Null until the company places the child on a van, which is the
            // step that turns an agreement into a seat.
            'route' => $this->whenLoaded('route', fn () => $this->route === null ? null : [
                'uuid' => $this->route->uuid,
                'name' => $this->route->name,
                'vehicle_plate' => $this->route->vehicle?->plate,
                'driver_name' => $this->route->driver?->user?->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
