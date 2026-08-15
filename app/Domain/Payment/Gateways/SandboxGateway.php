<?php

namespace App\Domain\Payment\Gateways;

use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\DTO\GatewayRedirect;
use App\Domain\Payment\DTO\GatewayVerification;
use App\Domain\Wallet\Models\Payment;
use Illuminate\Support\Str;

/**
 * Development gateway. It performs the full real flow — initiate, redirect,
 * verify — against a local page, so the wallet code path exercised in
 * development is exactly the one that will run in production. It refuses to
 * operate in production so it can never become a way to mint money.
 */
class SandboxGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function initiate(Payment $payment): GatewayRedirect
    {
        $this->assertNotProduction();

        $authority = 'SBX-'.Str::upper(Str::random(20));

        $payment->forceFill([
            'gateway_authority' => $authority,
            'expires_at' => now()->addMinutes(15),
        ])->save();

        return new GatewayRedirect(
            url: route('payments.sandbox', ['payment' => $payment->uuid]),
            authority: $authority,
        );
    }

    public function verify(Payment $payment, array $callback): GatewayVerification
    {
        $this->assertNotProduction();

        if (($callback['status'] ?? null) !== 'ok') {
            return GatewayVerification::failed('cancelled_by_user', $callback);
        }

        if (($callback['authority'] ?? null) !== $payment->gateway_authority) {
            return GatewayVerification::failed('authority_mismatch', $callback);
        }

        if ($payment->isExpired()) {
            return GatewayVerification::failed('expired', $callback);
        }

        return new GatewayVerification(
            successful: true,
            reference: 'SBXREF-'.Str::upper(Str::random(12)),
            amount: $payment->amount,
            cardMask: '6037-****-****-1234',
            raw: $callback,
        );
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    private function assertNotProduction(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('The sandbox payment gateway is disabled in production.');
        }
    }
}
