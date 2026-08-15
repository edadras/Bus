<?php

namespace App\Domain\Fleet\DTO;

/** Parsed representation of a scanned QR payload. */
final readonly class QrToken
{
    public function __construct(
        public int $version,
        public string $publicId,
        public int $timeStep,
        public string $nonce,
        public string $signature,
        public string $raw,
    ) {}
}
