<?php

namespace App\Domain\Notifications\Gateways;

use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\DTO\SmsResult;
use Illuminate\Support\Facades\Http;

/**
 * Kavenegar (kavenegar.com), one of the two providers most Iranian operators
 * are reached through.
 *
 * Two details of their API matter here:
 *
 *  - The API key is a path segment, not a header, so it must never appear in a
 *    log line or an exception message. Every failure path below reports the
 *    provider's own message and the HTTP status, never the URL.
 *  - A verification code must go through `verify/lookup` against a pattern
 *    approved in advance. Free-text OTPs are routinely filtered by the
 *    operators, so `sendTemplate` is the path sign-in actually uses and
 *    `send` is for everything else.
 *
 * Success is reported in a JSON body even on HTTP 200, so the return code is
 * checked rather than the status alone.
 */
class KavenegarSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'kavenegar';
    }

    public function send(string $mobile, string $message): SmsResult
    {
        return $this->call('sms/send.json', [
            'receptor' => $this->normalise($mobile),
            'message' => $message,
            'sender' => config('sms.gateways.kavenegar.sender'),
        ]);
    }

    public function sendTemplate(string $mobile, string $template, array $tokens): SmsResult
    {
        $values = array_values($tokens);

        return $this->call('verify/lookup.json', array_filter([
            'receptor' => $this->normalise($mobile),
            'template' => $template,
            'token' => $values[0] ?? null,
            'token2' => $values[1] ?? null,
            'token3' => $values[2] ?? null,
        ], fn ($value) => $value !== null));
    }

    /** @param array<string, mixed> $payload */
    private function call(string $path, array $payload): SmsResult
    {
        $key = (string) config('sms.gateways.kavenegar.api_key');

        if ($key === '') {
            return SmsResult::failed($this->name(), 'missing_api_key');
        }

        try {
            $response = Http::timeout((int) config('sms.timeout', 8))
                ->asForm()
                ->post("https://api.kavenegar.com/v1/{$key}/{$path}", $payload);
        } catch (\Throwable $e) {
            // A provider outage must never fail the request that triggered it.
            return SmsResult::failed($this->name(), 'transport_error');
        }

        $body = $response->json();
        $status = (int) data_get($body, 'return.status', $response->status());

        if (! $response->successful() || $status !== 200) {
            return SmsResult::failed(
                $this->name(),
                (string) data_get($body, 'return.message', 'unknown_error'),
                $status,
            );
        }

        return SmsResult::sent($this->name(), (string) data_get($body, 'entries.0.messageid'));
    }

    /** Kavenegar expects a national 09xxxxxxxxx number, not +98. */
    private function normalise(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        if (str_starts_with($digits, '98')) {
            $digits = '0'.substr($digits, 2);
        }

        return str_starts_with($digits, '0') ? $digits : '0'.$digits;
    }
}
