<?php

namespace App\Domain\Wallet\DTO;

use App\Domain\Wallet\Models\FareRule;
use App\Support\Money;

/** The priced outcome of a fare calculation, plus why it came out that way. */
final readonly class FareQuote
{
    public function __construct(
        public int $amount,
        public ?FareRule $rule,
        public string $currency,
        public array $breakdown = [],
    ) {}

    public function isFree(): bool
    {
        return $this->amount === 0;
    }

    public function formatted(): string
    {
        return Money::format($this->amount);
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'formatted' => $this->formatted(),
            'currency' => $this->currency,
            'rule' => $this->rule === null ? null : [
                'id' => $this->rule->id,
                'code' => $this->rule->code,
                'name' => $this->rule->name,
            ],
            'breakdown' => $this->breakdown,
        ];
    }
}
