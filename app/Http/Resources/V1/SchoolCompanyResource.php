<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A school service company.
 *
 * What a parent choosing one needs: who they are, how to reach them, and the
 * fact that somebody vetted them. The bank details are hidden on the model and
 * stay hidden here.
 */
class SchoolCompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'code' => $this->code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'is_approved' => $this->status->isVisibleToGuardians(),
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'description' => $this->description,
            'rating' => $this->rating,
            'contract_count' => $this->contract_count,
            'license_number' => $this->license_number,
            'license_expires_at' => $this->license_expires_at?->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'vehicle_count' => $this->whenCounted('vehicles'),
            'route_count' => $this->whenCounted('routes'),
        ];
    }
}
