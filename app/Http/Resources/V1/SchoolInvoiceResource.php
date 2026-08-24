<?php

namespace App\Http\Resources\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'is_payable' => $this->status->isPayable(),
            'is_overdue' => $this->resource->isOverdue(),
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'amount' => $this->amount,
            'formatted_amount' => Money::format($this->amount),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'contract' => $this->whenLoaded('contract', fn () => [
                'uuid' => $this->contract->uuid,
                'reference' => $this->contract->reference,
                'student_name' => $this->contract->student?->name,
            ]),
        ];
    }
}
