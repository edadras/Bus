<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\PushSubscription;
use Illuminate\Support\Collection;

/**
 * One entry point for push, whichever kind of device is on the other end.
 *
 * A user is not "a web user" or "a phone user" — they are one account with a
 * browser at their desk and an app in their pocket, and a bus-arrival alert
 * belongs on both. Callers therefore address a *user*, and this splits their
 * devices by platform: browsers over Web Push with VAPID, phones over FCM.
 *
 * A deployment that has configured neither still works; nothing is delivered
 * out of app, and the in-app inbox remains the complete record.
 */
class PushSender
{
    public function __construct(
        private readonly WebPushSender $web,
        private readonly FcmSender $native,
    ) {}

    public function isConfigured(): bool
    {
        return $this->web->isConfigured() || $this->native->isConfigured();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{sent: int, expired: int, failed: int}
     */
    public function sendToUser(int $userId, array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['sent' => 0, 'expired' => 0, 'failed' => 0];
        }

        return $this->send(PushSubscription::where('user_id', $userId)->get(), $payload);
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     * @return array{sent: int, expired: int, failed: int}
     */
    public function send(Collection $subscriptions, array $payload): array
    {
        // Anything that is not explicitly a native platform is treated as a
        // browser, because that is what the first release stored and those
        // rows must keep working.
        [$native, $web] = $subscriptions->partition(
            fn (PushSubscription $subscription) => in_array($subscription->platform, ['android', 'ios'], true),
        );

        $totals = ['sent' => 0, 'expired' => 0, 'failed' => 0];

        foreach ([$this->web->send($web, $payload), $this->native->send($native, $payload)] as $result) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
        }

        return $totals;
    }
}
