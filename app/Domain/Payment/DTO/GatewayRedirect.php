<?php

namespace App\Domain\Payment\DTO;

final readonly class GatewayRedirect
{
    public function __construct(
        public string $url,
        public ?string $authority = null,
        public string $method = 'GET',
        public array $fields = [],
    ) {}
}
