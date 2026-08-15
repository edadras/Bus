<?php

namespace App\Http\Resources\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'balance' => $this->balance,
            'available_balance' => $this->availableBalance(),
            'formatted_balance' => Money::format($this->balance),
            'currency' => $this->currency,
            'display_unit' => config('wallet.display_unit'),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'can_spend' => $this->status->canDebit(),
            'version' => $this->version,
        ];
    }
}
