<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One child on one run, as the driver's manifest shows them.
 *
 * The medical note and the emergency number are here because this is the screen
 * a driver is looking at when either becomes relevant.
 */
class SchoolTripStudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'sequence' => $this->sequence,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),

            'pickup_address' => $this->pickup_address,
            'pickup_lat' => $this->pickup_lat,
            'pickup_lng' => $this->pickup_lng,

            'picked_up_at' => $this->picked_up_at?->toIso8601String(),
            'dropped_off_at' => $this->dropped_off_at?->toIso8601String(),
            'note' => $this->note,

            'student' => $this->whenLoaded('student', fn () => [
                'uuid' => $this->student->uuid,
                'name' => $this->student->name,
                'grade' => $this->student->grade,
                'photo_path' => $this->student->photo_path,
                'medical_notes' => $this->student->medical_notes,
                'emergency_contact_name' => $this->student->emergency_contact_name,
                'emergency_contact_phone' => $this->student->emergency_contact_phone,
                'guardian_phone' => $this->student->guardian?->mobile,
            ]),
        ];
    }
}
