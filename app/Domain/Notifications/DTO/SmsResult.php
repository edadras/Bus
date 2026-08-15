<?php

namespace App\Domain\Notifications\DTO;

/**
 * The outcome of one send attempt.
 *
 * `messageId` is what a provider's delivery-report webhook will quote back, so
 * it is worth keeping even though nothing consumes it yet.
 */
final readonly class SmsResult
{
    private function __construct(
        public bool $delivered,
        public string $gateway,
        public ?string $messageId = null,
        public ?string $error = null,
        public ?int $status = null,
    ) {}

    public static function sent(string $gateway, ?string $messageId = null): self
    {
        return new self(true, $gateway, $messageId);
    }

    public static function failed(string $gateway, string $error, ?int $status = null): self
    {
        return new self(false, $gateway, null, $error, $status);
    }
}
