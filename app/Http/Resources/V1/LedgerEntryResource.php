<?php

namespace App\Http\Resources\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A statement line, presented from the wallet owner's point of view: a credit
 * reads as positive, a debit as negative, regardless of the ledger's internal
 * double entry structure.
 */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $transaction = $this->whenLoaded('transaction');

        return [
            'id' => $this->id,
            'direction' => $this->direction->value,
            'amount' => $this->amount,
            'signed_amount' => $this->signedAmount(),
            'formatted_amount' => Money::format($this->amount),
            'balance_after' => $this->balance_after,
            'formatted_balance' => Money::format($this->balance_after),
            'sequence' => $this->sequence,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'transaction' => $this->when($this->relationLoaded('transaction') && $this->transaction !== null, fn () => [
                'uuid' => $this->transaction->uuid,
                'type' => $this->transaction->type->value,
                'type_label' => $this->transaction->type->label(),
                'status' => $this->transaction->status->value,
                'color' => $this->transaction->type->color(),
            ]),
        ];
    }
}
