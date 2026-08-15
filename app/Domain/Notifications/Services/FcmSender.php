<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\PushSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Native push through Firebase Cloud Messaging, HTTP v1.
 *
 * Two things here are worth knowing:
 *
 *  - **Authentication is an OAuth token, minted locally.** v1 does not accept
 *    the old static server key. A JWT is signed with the service account's RSA
 *    key and exchanged for an access token, which is then cached for just
 *    under its hour of validity. That is the whole reason this does not need
 *    the Google API client library.
 *  - **UNREGISTERED and NOT_FOUND mean the token is dead** — the app was
 *    uninstalled, or the token was rotated. Those rows are deleted at once.
 *    Without that the subscription table grows without bound and every
 *    notification wastes a request per dead device.
 *
 * Every other failure is transient and left alone for the next notification
 * to retry.
 */
class FcmSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_CACHE_KEY = 'fcm:access-token';

    public function isConfigured(): bool
    {
        return filled(config('fcm.project_id'))
            && filled(config('fcm.credentials'))
            && is_readable((string) config('fcm.credentials'));
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     * @return array{sent: int, expired: int, failed: int}
     */
    public function send(Collection $subscriptions, array $payload): array
    {
        $result = ['sent' => 0, 'expired' => 0, 'failed' => 0];

        if (! $this->isConfigured() || $subscriptions->isEmpty()) {
            return $result;
        }

        $token = $this->accessToken();

        if ($token === null) {
            return $result;
        }

        $url = sprintf(
            'https://fcm.googleapis.com/v1/projects/%s/messages:send',
            config('fcm.project_id'),
        );

        foreach ($subscriptions as $subscription) {
            try {
                $response = Http::withToken($token)
                    ->timeout((int) config('fcm.timeout', 8))
                    ->post($url, ['message' => $this->message($subscription, $payload)]);
            } catch (\Throwable $e) {
                $result['failed']++;

                continue;
            }

            if ($response->successful()) {
                $result['sent']++;
                $subscription->forceFill(['last_used_at' => now()])->save();

                continue;
            }

            $status = (string) $response->json('error.status', '');

            // The device is gone for good.
            if (in_array($status, ['UNREGISTERED', 'NOT_FOUND'], true) || $response->status() === 404) {
                $result['expired']++;
                $subscription->delete();

                continue;
            }

            $result['failed']++;

            Log::info('FCM delivery failed.', [
                'status' => $status ?: $response->status(),
                // The registration token identifies a device; never log it.
                'platform' => $subscription->platform,
            ]);
        }

        return $result;
    }

    /**
     * Build one FCM v1 message.
     *
     * `notification` is what the OS displays when the app is backgrounded;
     * `data` is what the app reads when it is running. Both are sent, because
     * the app has to behave the same either way, and the platform blocks pin
     * down the two behaviours that actually differ: Android needs the channel
     * id it registered, iOS needs an explicit sound to make a noise at all.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function message(PushSubscription $subscription, array $payload): array
    {
        $data = array_map(
            static fn ($value) => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            array_filter($payload, static fn ($value) => $value !== null),
        );

        return [
            'token' => $subscription->endpoint,
            'notification' => array_filter([
                'title' => $payload['title'] ?? null,
                'body' => $payload['body'] ?? null,
            ]),
            'data' => $data,
            'android' => [
                'priority' => 'high',
                'ttl' => ((int) config('fcm.ttl', 300)).'s',
                'notification' => array_filter([
                    'channel_id' => 'hamsafar_alerts',
                    'tag' => $payload['tag'] ?? null,
                ]),
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => '10',
                    'apns-expiration' => (string) (time() + (int) config('fcm.ttl', 300)),
                ],
                'payload' => [
                    'aps' => array_filter([
                        'sound' => 'default',
                        'thread-id' => $payload['tag'] ?? null,
                    ]),
                ],
            ],
        ];
    }

    /**
     * An OAuth access token for the messaging scope.
     *
     * Cached for 55 minutes against its hour of validity — long enough to make
     * the exchange rare, short enough that a clock a few minutes out never
     * presents an expired token.
     */
    private function accessToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached)) {
            return $cached;
        }

        $credentials = $this->credentials();

        if ($credentials === null) {
            return null;
        }

        $assertion = $this->signedAssertion($credentials);

        if ($assertion === null) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('fcm.timeout', 8))
                ->post($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
        } catch (\Throwable $e) {
            Log::warning('FCM token exchange failed.', ['reason' => 'transport_error']);

            return null;
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token)) {
            Log::warning('FCM token exchange rejected.', [
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            return null;
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addMinutes(55));

        return $token;
    }

    /** @return array<string, mixed>|null */
    private function credentials(): ?array
    {
        $path = (string) config('fcm.credentials');

        if (! is_readable($path)) {
            return null;
        }

        try {
            $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('FCM credentials file is not valid JSON.');

            return null;
        }

        return isset($json['client_email'], $json['private_key']) ? $json : null;
    }

    /** @param array<string, mixed> $credentials */
    private function signedAssertion(array $credentials): ?string
    {
        $now = time();

        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';

        if (! openssl_sign("$header.$claims", $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            Log::warning('FCM assertion could not be signed; check the service-account key.');

            return null;
        }

        return $header.'.'.$claims.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
