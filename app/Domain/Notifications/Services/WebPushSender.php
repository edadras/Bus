<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\PushSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push delivery.
 *
 * The one behaviour that matters operationally: a push service answering 404
 * or 410 means the subscription is dead — the browser was uninstalled, or the
 * user cleared site data. Those rows are deleted immediately. Without that,
 * the subscription table grows without bound and every notification wastes a
 * round trip per dead endpoint.
 *
 * Any other failure is transient and left alone to be retried by the next
 * notification.
 */
class WebPushSender
{
    public function isConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    public function publicKey(): ?string
    {
        return config('webpush.vapid.public_key');
    }

    /**
     * Deliver one payload to every device belonging to a user.
     *
     * @param  array<string, mixed>  $payload
     * @return array{sent: int, expired: int, failed: int}
     */
    public function sendToUser(int $userId, array $payload): array
    {
        // Checked before the query: on a deployment without VAPID keys this
        // would otherwise cost one pointless SELECT per notification sent.
        if (! $this->isConfigured()) {
            return ['sent' => 0, 'expired' => 0, 'failed' => 0];
        }

        return $this->send(
            PushSubscription::where('user_id', $userId)->get(),
            $payload,
        );
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

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => (string) config('webpush.vapid.subject'),
                    'publicKey' => (string) config('webpush.vapid.public_key'),
                    'privateKey' => (string) config('webpush.vapid.private_key'),
                ],
            ], [
                'TTL' => (int) config('webpush.ttl'),
                'urgency' => (string) config('webpush.urgency'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Web Push could not be initialised.', ['message' => $e->getMessage()]);

            return $result;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $byEndpoint = $subscriptions->keyBy('endpoint');

        foreach ($subscriptions as $subscription) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->public_key,
                        'authToken' => $subscription->auth_token,
                        'contentEncoding' => 'aesgcm',
                    ]),
                    $body,
                );
            } catch (\Throwable $e) {
                // A malformed stored subscription can never succeed; drop it.
                $subscription->delete();
                $result['expired']++;
            }
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $result['sent']++;
                $byEndpoint->get($endpoint)?->forceFill(['last_used_at' => now()])->save();

                continue;
            }

            // 404 / 410: the endpoint is gone for good.
            if ($report->isSubscriptionExpired()) {
                $result['expired']++;
                PushSubscription::where('endpoint', $endpoint)->delete();

                continue;
            }

            $result['failed']++;

            Log::info('Web Push delivery failed.', [
                'reason' => $report->getReason(),
                // The endpoint contains a device-identifying token, so only its
                // origin is logged.
                'host' => parse_url($endpoint, PHP_URL_HOST),
            ]);
        }

        return $result;
    }
}
