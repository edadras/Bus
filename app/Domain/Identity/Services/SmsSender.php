<?php

namespace App\Domain\Identity\Services;

use App\Domain\Notifications\DTO\SmsResult;
use App\Domain\Notifications\Services\SmsGatewayManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SMS delivery, in front of whichever provider is configured.
 *
 * The contract this class keeps with its callers is that **sending never
 * throws**. An SMS provider sits on the critical path of sign-in; if it is
 * down, slow, or misconfigured, the right outcome is a user who does not
 * receive a code and can press resend — not a 500 on the sign-in request.
 * Failures are logged and returned, never raised.
 *
 * The message body is only ever logged outside production, because an OTP body
 * is a credential and a log file is not the place for one.
 */
class SmsSender
{
    public function __construct(private readonly SmsGatewayManager $gateways) {}

    public function sendOtp(string $mobile, string $code): SmsResult
    {
        $gateway = $this->gateways->driver();
        $template = $this->gateways->otpTemplate();
        $ttlMinutes = (int) ((int) config('transit.otp.ttl_seconds', 300) / 60);

        // Operators filter free-text verification codes, so a provider with a
        // pre-approved pattern must be used through it.
        $result = $template !== null
            ? $gateway->sendTemplate($mobile, $template, ['code' => $code, 'ttl' => (string) $ttlMinutes])
            : $gateway->send($mobile, __('sms.otp', ['code' => $code, 'ttl' => $ttlMinutes]));

        return $this->record($result, $mobile, 'otp');
    }

    public function send(string $mobile, string $message): SmsResult
    {
        return $this->record($this->gateways->driver()->send($mobile, $message), $mobile, 'message');
    }

    private function record(SmsResult $result, string $mobile, string $kind): SmsResult
    {
        if ($result->delivered) {
            return $result;
        }

        Log::channel('sms')->warning('SMS delivery failed.', [
            'kind' => $kind,
            'gateway' => $result->gateway,
            'error' => $result->error,
            'status' => $result->status,
            'mobile' => Str::substr($mobile, 0, 6).'***'.Str::substr($mobile, -2),
        ]);

        return $result;
    }
}
