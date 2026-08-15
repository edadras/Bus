<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->whenLoaded('user', fn () => $this->user->name),
            'mobile' => $this->whenLoaded('user', fn () => $this->user->mobile),
            'employee_code' => $this->employee_code,
            'license_number' => $this->license_number,
            'license_class' => $this->license_class,
            'license_expires_at' => $this->license_expires_at?->toDateString(),
            // The two dates that stop a shift regardless of status, surfaced
            // as answers rather than as dates the panel has to compare itself.
            'license_is_expired' => $this->license_expires_at?->isPast() === true,
            'contract_ends_at' => $this->contract_ends_at?->toDateString(),
            'contract_has_ended' => $this->contract_ends_at?->isPast() === true,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'hired_at' => $this->hired_at?->toDateString(),
            'total_trips' => $this->total_trips,
            'total_shift_minutes' => $this->total_shift_minutes,
            'rating' => $this->rating,
            'operator' => $this->whenLoaded('operator', fn () => $this->operator?->name),
        ];
    }
}
