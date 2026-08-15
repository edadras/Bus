<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'body' => $this->body,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'is_open' => $this->status->isOpen(),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
            'satisfaction_rating' => $this->satisfaction_rating,
            'bus_number' => $this->whenLoaded('bus', fn () => $this->bus?->bus_number),
            'line' => new LineSummaryResource($this->whenLoaded('line')),
            // Passengers only ever receive the non-internal thread.
            'messages' => ComplaintMessageResource::collection($this->whenLoaded('publicMessages')),
            'attachment_count' => $this->whenCounted('attachments'),
        ];
    }
}
