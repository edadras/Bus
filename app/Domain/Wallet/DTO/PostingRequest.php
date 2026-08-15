<?php

namespace App\Domain\Wallet\DTO;

use App\Domain\Identity\Models\User;
use App\Domain\Wallet\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything the ledger needs to write one transaction. Built by the calling
 * service so the ledger itself stays free of any business vocabulary.
 */
final class PostingRequest
{
    /** @param array<int, PostingLine> $lines */
    public function __construct(
        public readonly TransactionType $type,
        public readonly array $lines,
        public readonly int $amount,
        public readonly ?string $idempotencyKey = null,
        public readonly ?User $initiatedBy = null,
        public readonly ?Model $subject = null,
        public readonly ?int $cityId = null,
        public readonly ?string $description = null,
        public readonly array $metadata = [],
        /** Skip the daily spend limit; used by reversals and settlements. */
        public readonly bool $bypassSpendLimit = false,
    ) {}

    /** @return array<int, PostingLine> */
    public function debits(): array
    {
        return array_values(array_filter($this->lines, fn (PostingLine $l) => $l->direction->value === 'debit'));
    }

    public function isBalanced(): bool
    {
        return array_sum(array_map(fn (PostingLine $l) => $l->signedAmount(), $this->lines)) === 0;
    }
}
