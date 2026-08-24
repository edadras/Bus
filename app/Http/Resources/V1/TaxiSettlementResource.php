<?php

namespace App\Http\Resources\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxiSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'ride_count' => $this->ride_count,
            'gross_amount' => $this->gross_amount,
            'commission_amount' => $this->commission_amount,
            'net_amount' => $this->net_amount,
            'formatted_net' => Money::format($this->net_amount),
            'payment_reference' => $this->payment_reference,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'driver' => $this->whenLoaded('driver', fn () => [
                'uuid' => $this->driver->uuid,
                'name' => $this->driver->user?->name,
            ]),
        ];
    }
}
