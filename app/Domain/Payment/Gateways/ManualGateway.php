<?php

namespace App\Domain\Payment\Gateways;

use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\DTO\GatewayRedirect;
use App\Domain\Payment\DTO\GatewayVerification;
use App\Domain\Wallet\Models\Payment;

/**
 * Counter top-ups: a passenger hands cash to a kiosk operator, who confirms it
 * in the admin panel. The "verification" is a finance user's approval, which is
 * why this gateway never self-approves.
 */
class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function initiate(Payment $payment): GatewayRedirect
    {
        $payment->forceFill([
            'gateway_authority' => 'MANUAL-'.$payment->uuid,
            'expires_at' => now()->addDay(),
        ])->save();

        return new GatewayRedirect(url: route('payments.pending', ['payment' => $payment->uuid]));
    }

    public function verify(Payment $payment, array $callback): GatewayVerification
    {
        if (($callback['approved'] ?? false) !== true) {
            return GatewayVerification::failed('awaiting_manual_approval', $callback);
        }

        return new GatewayVerification(
            successful: true,
            reference: $callback['reference'] ?? 'MANUAL-'.$payment->uuid,
            amount: $payment->amount,
            raw: $callback,
        );
    }

    public function supportsRefund(): bool
    {
        return false;
    }
}
