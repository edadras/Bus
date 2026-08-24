<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolStudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->resource->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'grade' => $this->grade,
            'classroom' => $this->classroom,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->toDateString(),
            'pickup_address' => $this->pickup_address,
            'pickup_lat' => $this->pickup_lat,
            'pickup_lng' => $this->pickup_lng,
            // Carried so a driver has it in the moment it matters rather than
            // in a file somebody would have to go and find.
            'medical_notes' => $this->medical_notes,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'is_active' => $this->is_active,
            'school' => $this->whenLoaded('school', fn () => $this->school === null ? null : [
                'uuid' => $this->school->uuid,
                'name' => $this->school->name,
                'starts_at' => $this->school->starts_at,
                'ends_at' => $this->school->ends_at,
            ]),
        ];
    }
}
