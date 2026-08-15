<?php

namespace App\Domain\Notifications\Gateways;

use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\DTO\SmsResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The default: writes to the `sms` log channel instead of sending.
 *
 * This is the correct driver for development and for any deployment that has
 * not yet contracted a provider — sign-in keeps working, and the code is in
 * the log where a developer can read it.
 *
 * In production it deliberately logs the recipient only. The body of an OTP
 * message is a credential, and a log file is not the place for one.
 */
class LogSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $mobile, string $message): SmsResult
    {
        if (app()->environment('production')) {
            Log::channel('sms')->info('SMS dispatched', ['mobile' => $this->mask($mobile)]);
        } else {
            Log::channel('sms')->info('SMS', ['mobile' => $mobile, 'message' => $message]);
        }

        return SmsResult::sent($this->name(), 'log-'.Str::uuid()->toString());
    }

    public function sendTemplate(string $mobile, string $template, array $tokens): SmsResult
    {
        return $this->send($mobile, $template.': '.implode(', ', $tokens));
    }

    private function mask(string $mobile): string
    {
        return Str::substr($mobile, 0, 6).'***'.Str::substr($mobile, -2);
    }
}
