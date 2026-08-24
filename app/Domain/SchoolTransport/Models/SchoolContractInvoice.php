<?php

namespace App\Domain\SchoolTransport\Models;

use App\Domain\SchoolTransport\Enums\SchoolInvoiceStatus;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One period's fee on one contract. */
class SchoolContractInvoice extends Model
{
    use Auditable;
    use BelongsToCity;
    use HasUuid;

    protected $fillable = [
        'school_service_contract_id', 'city_id', 'reference', 'status',
        'period_start', 'period_end', 'due_on', 'amount', 'commission_amount',
        'wallet_transaction_id', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_on' => 'date',
            'amount' => 'integer',
            'commission_amount' => 'integer',
            'paid_at' => 'datetime',
            'status' => SchoolInvoiceStatus::class,
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(SchoolServiceContract::class, 'school_service_contract_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function scopePayable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SchoolInvoiceStatus::Pending->value,
            SchoolInvoiceStatus::Overdue->value,
        ]);
    }

    public function isOverdue(): bool
    {
        return $this->status->isPayable() && $this->due_on?->isPast() === true;
    }
}
