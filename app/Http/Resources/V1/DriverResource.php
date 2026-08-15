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
            'license_expires_at' => $this->license_expires_at?->toDateString(),
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
