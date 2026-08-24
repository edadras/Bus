<?php

namespace App\Http\Resources\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A taxi ride as the passenger sees it.
 *
 * The breakdown travels with the ride rather than being recomputed for
 * display: a metered fare is the one charge nobody could check in advance, so
 * the receipt has to be the arithmetic that actually ran.
 */
class TaxiRideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'service_type' => $this->service_type->value,
            'service_type_label' => $this->service_type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'is_open' => $this->status->isOpen(),

            'fare_amount' => $this->fare_amount,
            'formatted_fare' => Money::format($this->fare_amount),
            'outstanding_amount' => $this->outstanding_amount,
            'fare_breakdown' => $this->fare_breakdown,

            'distance_meters' => $this->distance_meters,
            'waiting_seconds' => $this->waiting_seconds,
            'duration_seconds' => $this->duration_seconds,

            'taxi' => $this->whenLoaded('taxi', fn () => [
                'uuid' => $this->taxi->uuid,
                'taxi_number' => $this->taxi->taxi_number,
                'plate' => $this->taxi->plate,
                'color' => $this->taxi->color,
                'model' => $this->taxi->model,
            ]),
            'line' => $this->whenLoaded('line', fn () => $this->line === null ? null : [
                'code' => $this->line->code,
                'name' => $this->line->name,
            ]),
            'driver_name' => $this->whenLoaded('driver', fn () => $this->driver?->user?->name),

            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
        ];
    }
}
