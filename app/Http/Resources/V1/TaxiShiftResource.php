<?php

namespace App\Http\Resources\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The driver's own shift: what they are offering, and what they have taken. */
class TaxiShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'service_type' => $this->service_type->value,
            'service_type_label' => $this->service_type->label(),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_minutes' => $this->resource->durationMinutes(),

            'line' => $this->whenLoaded('line', fn () => $this->line === null ? null : [
                'id' => $this->line->id,
                'code' => $this->line->code,
                'name' => $this->line->name,
                'flat_fare' => (int) $this->line->flat_fare,
                'formatted_fare' => Money::format((int) $this->line->flat_fare),
            ]),

            // Null once it has been taken or has gone stale, which is the same
            // thing to the driver: there is no price standing right now.
            'pending_charter_amount' => $this->resource->pendingCharterAmount(),

            'ride_count' => $this->ride_count,
            'boarding_count' => $this->boarding_count,
            'alighting_count' => $this->alighting_count,
            'onboard_count' => $this->onboard_count,

            'gross_minor' => $this->gross_minor,
            'commission_minor' => $this->commission_minor,
            'net_minor' => $this->net_minor,
            'formatted_gross' => Money::format($this->gross_minor),
            'formatted_net' => Money::format($this->net_minor),

            'taxi' => $this->whenLoaded('taxi', fn () => (new TaxiResource($this->taxi))->resolve()),
        ];
    }
}
