<?php

namespace App\Domain\Payment\DTO;

final readonly class GatewayVerification
{
    public function __construct(
        public bool $successful,
        public ?string $reference = null,
        public ?int $amount = null,
        public ?string $cardMask = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}

    public static function failed(string $reason, array $raw = []): self
    {
        return new self(false, failureReason: $reason, raw: $raw);
    }
}
