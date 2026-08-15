<?php

namespace App\Domain\Identity\Services;

use Illuminate\Support\Facades\Log;

/**
 * SMS delivery seam. The default implementation logs, which is correct for
 * development and for any deployment that has not yet contracted a provider;
 * bind a real driver in a service provider to send for real.
 */
class SmsSender
{
    public function sendOtp(string $mobile, string $code): void
    {
        $this->send($mobile, __('sms.otp', ['code' => $code, 'ttl' => (int) config('transit.otp.ttl_seconds') / 60]));
    }

    public function send(string $mobile, string $message): void
    {
        // Never log the message body in production: it contains the OTP.
        if (app()->environment('production')) {
            Log::channel('sms')->info('SMS dispatched', ['mobile' => substr($mobile, 0, 6).'***']);

            return;
        }

        Log::channel('sms')->info('SMS', ['mobile' => $mobile, 'message' => $message]);
    }
}
