<?php

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\DTO\SmsResult;

/**
 * SMS providers differ in wire format but not in shape: hand over a number and
 * a body, find out whether it was accepted. New providers implement this and
 * register in config/sms.php; nothing else changes.
 *
 * An implementation must never throw for a delivery failure — a provider being
 * down must not fail the sign-in request that triggered it. Return a failed
 * SmsResult and let the caller decide.
 */
interface SmsGateway
{
    public function name(): string;

    public function send(string $mobile, string $message): SmsResult;

    /**
     * Send using the provider's pre-approved template mechanism.
     *
     * Iranian operators require verification codes to go out through a
     * registered pattern rather than as free text, and a plain send of an OTP
     * is routinely filtered. A provider without templates falls back to a
     * normal send.
     *
     * @param  array<string, string>  $tokens
     */
    public function sendTemplate(string $mobile, string $template, array $tokens): SmsResult;
}
