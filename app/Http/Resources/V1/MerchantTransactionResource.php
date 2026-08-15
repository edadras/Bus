<?php

namespace App\Http\Resources\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'amount' => $this->amount,
            'formatted_amount' => Money::format($this->amount),
            'commission_amount' => $this->commission_amount,
            'net_amount' => $this->net_amount,
            'formatted_net' => Money::format($this->net_amount),
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'is_settled' => $this->settlement_id !== null,
            'merchant' => $this->whenLoaded('merchant', fn () => [
                'name' => $this->merchant->name,
                'type' => $this->merchant->type->value,
                'type_label' => $this->merchant->type->label(),
            ]),
            // Payer identity is masked: cashiers need to recognise a payment,
            // not to collect customers' phone numbers.
            'payer' => $this->whenLoaded('user', fn () => [
                'name' => $this->user->name,
                'mobile' => $this->user->maskedMobile(),
            ]),
        ];
    }
}
