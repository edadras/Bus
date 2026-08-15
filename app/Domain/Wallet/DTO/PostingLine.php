<?php

namespace App\Domain\Wallet\DTO;

use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Models\Wallet;

/** One leg of a double entry posting. */
final readonly class PostingLine
{
    public function __construct(
        public Wallet $wallet,
        public LedgerDirection $direction,
        public int $amount,
        public ?string $description = null,
        public array $metadata = [],
    ) {}

    public static function debit(Wallet $wallet, int $amount, ?string $description = null, array $metadata = []): self
    {
        return new self($wallet, LedgerDirection::Debit, $amount, $description, $metadata);
    }

    public static function credit(Wallet $wallet, int $amount, ?string $description = null, array $metadata = []): self
    {
        return new self($wallet, LedgerDirection::Credit, $amount, $description, $metadata);
    }

    public function signedAmount(): int
    {
        return $this->direction->signedAmount($this->amount);
    }
}
