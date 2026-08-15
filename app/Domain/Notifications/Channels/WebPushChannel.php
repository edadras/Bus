<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Services\WebPushSender;
use Illuminate\Notifications\Notification;

/**
 * Laravel notification channel backed by Web Push.
 *
 * A notification opts in by implementing `toWebPush()`. Anything that does not
 * is skipped silently rather than throwing, so adding push to one notification
 * never breaks the others.
 */
class WebPushChannel
{
    public function __construct(private readonly WebPushSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWebPush')) {
            return;
        }

        $userId = $notifiable->getKey();

        if (! is_int($userId)) {
            return;
        }

        $this->sender->sendToUser($userId, $notification->toWebPush($notifiable));
    }
}
