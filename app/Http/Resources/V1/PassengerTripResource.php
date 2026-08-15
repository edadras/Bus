<?php

namespace App\Http\Resources\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PassengerTripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'boarded_at' => $this->boarded_at?->toIso8601String(),
            'alighted_at' => $this->alighted_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'distance_meters' => $this->distance_meters,
            'fare' => [
                'amount' => $this->fare_amount,
                'formatted' => Money::format($this->fare_amount),
            ],
            'line' => new LineSummaryResource($this->whenLoaded('line')),
            'bus_number' => $this->whenLoaded('bus', fn () => $this->bus->bus_number),
            'boarding_stop' => $this->whenLoaded('boardingStop', fn () => $this->boardingStop?->name),
            'alighting_stop' => $this->whenLoaded('alightingStop', fn () => $this->alightingStop?->name),
            'trip_uuid' => $this->whenLoaded('trip', fn () => $this->trip->uuid),
            'transaction_uuid' => $this->whenLoaded('transaction', fn () => $this->transaction?->uuid),
        ];
    }
}
