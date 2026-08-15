<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\DTO\GatewayRedirect;
use App\Domain\Payment\DTO\GatewayVerification;
use App\Domain\Wallet\Models\Payment;

/**
 * Payment providers differ in wire format but not in shape: start a payment,
 * send the user somewhere, then verify what came back. New providers implement
 * this and register in config/wallet.php; nothing else changes.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Begin a payment and return where to send the user. */
    public function initiate(Payment $payment): GatewayRedirect;

    /**
     * Verify a callback. Must be authoritative: the wallet is only credited on
     * a successful verification, never on the redirect alone, because a
     * redirect is attacker-controllable and a verification is not.
     *
     * @param array<string, mixed> $callback
     */
    public function verify(Payment $payment, array $callback): GatewayVerification;

    public function supportsRefund(): bool;
}
