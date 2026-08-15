<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bus-to-driver assignment, as the admin panel lists it.
 *
 * `is_current` is what an operator actually reads: an assignment can be
 * active in the database yet not in force today because it starts next week
 * or ended yesterday, and only the ones in force let a driver open a shift.
 */
class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'driver_uuid' => $this->whenLoaded('driver', fn () => $this->driver?->uuid),
            'driver_name' => $this->whenLoaded('driver', fn () => $this->driver?->user?->name),
            'line' => new LineSummaryResource($this->whenLoaded('line')),
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'is_active' => $this->is_active,
            // The same window Driver::mayOperate() enforces, so the panel
            // cannot show an assignment as in force that the server would
            // refuse a shift on.
            'is_current' => $this->is_active
                && $this->starts_on !== null
                && ! $this->starts_on->isAfter(today())
                && ($this->ends_on === null || ! $this->ends_on->isBefore(today())),
        ];
    }
}
