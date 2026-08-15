<?php

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Base class for every expected business rule failure. Domain exceptions carry
 * a stable machine readable code so clients can branch on the failure without
 * parsing translated text.
 */
class DomainException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        protected string $errorCode = 'domain_error',
        string $message = '',
        protected int $httpStatus = 422,
        protected array $context = [],
    ) {
        parent::__construct($message !== '' ? $message : __('errors.'.$errorCode));
    }

    /** @param array<string, mixed> $context */
    public static function make(string $code, int $status = 422, array $context = [], string $message = ''): static
    {
        return new static($code, $message, $status, $context);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
