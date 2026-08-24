<?php

namespace App\Http\Resources\V1;

use App\Domain\Taxi\Enums\TaxiServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'taxi_number' => $this->taxi_number,
            'plate' => $this->plate,
            'model' => $this->model,
            'color' => $this->color,
            'capacity' => $this->capacity,
            'is_accessible' => $this->is_accessible,
            'has_air_conditioning' => $this->has_air_conditioning,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'allowed_modes' => array_map(
                static fn (TaxiServiceType $mode) => $mode->value,
                $this->resource->allowedModes(),
            ),
            'commission_bps' => $this->resource->commissionBps(),
            'default_line' => $this->whenLoaded('defaultLine', fn () => $this->defaultLine === null ? null : [
                'id' => $this->defaultLine->id,
                'code' => $this->defaultLine->code,
                'name' => $this->defaultLine->name,
            ]),
            'current_driver' => $this->whenLoaded('currentDriver', fn () => $this->currentDriver === null ? null : [
                'uuid' => $this->currentDriver->uuid,
                'name' => $this->currentDriver->user?->name,
            ]),
            'last_ping_at' => $this->last_ping_at?->toIso8601String(),
            'inspection_due_at' => $this->inspection_due_at?->toDateString(),
            'notes' => $this->notes,
        ];
    }
}
