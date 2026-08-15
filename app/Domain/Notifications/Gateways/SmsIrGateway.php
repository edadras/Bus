<?php

namespace App\Domain\Notifications\Gateways;

use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\DTO\SmsResult;
use Illuminate\Support\Facades\Http;

/**
 * SMS.ir, the other provider commonly contracted in Iran.
 *
 * Unlike Kavenegar the key travels as a header, and templates are addressed by
 * a numeric id with named parameters. The shape of the two drivers is
 * otherwise identical, which is the point of the contract: swapping provider
 * is one line of config.
 */
class SmsIrGateway implements SmsGateway
{
    private const BASE = 'https://api.sms.ir/v1';

    public function name(): string
    {
        return 'smsir';
    }

    public function send(string $mobile, string $message): SmsResult
    {
        return $this->call('send/bulk', [
            'lineNumber' => config('sms.gateways.smsir.line_number'),
            'messageText' => $message,
            'mobiles' => [$this->normalise($mobile)],
        ]);
    }

    public function sendTemplate(string $mobile, string $template, array $tokens): SmsResult
    {
        return $this->call('send/verify', [
            'mobile' => $this->normalise($mobile),
            'templateId' => (int) $template,
            'parameters' => array_map(
                static fn (string $name, string $value) => ['name' => $name, 'value' => $value],
                array_keys($tokens),
                array_values($tokens),
            ),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function call(string $path, array $payload): SmsResult
    {
        $key = (string) config('sms.gateways.smsir.api_key');

        if ($key === '') {
            return SmsResult::failed($this->name(), 'missing_api_key');
        }

        try {
            $response = Http::timeout((int) config('sms.timeout', 8))
                ->withHeaders(['X-API-KEY' => $key, 'Accept' => 'application/json'])
                ->post(self::BASE.'/'.$path, $payload);
        } catch (\Throwable $e) {
            return SmsResult::failed($this->name(), 'transport_error');
        }

        $body = $response->json();
        $status = (int) data_get($body, 'status', $response->status());

        // SMS.ir signals success with status 1, not 200.
        if (! $response->successful() || $status !== 1) {
            return SmsResult::failed(
                $this->name(),
                (string) data_get($body, 'message', 'unknown_error'),
                $status,
            );
        }

        return SmsResult::sent($this->name(), (string) data_get($body, 'data.messageId'));
    }

    private function normalise(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        if (str_starts_with($digits, '98')) {
            $digits = '0'.substr($digits, 2);
        }

        return str_starts_with($digits, '0') ? $digits : '0'.$digits;
    }
}
