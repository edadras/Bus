<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Services\PushSender;
use Illuminate\Notifications\Notification;

/**
 * Laravel notification channel for push, registered as `push`.
 *
 * A notification opts in by implementing `toPush()`. Anything that does not is
 * skipped silently rather than throwing, so adding push to one notification
 * never breaks the others.
 */
class PushChannel
{
    public function __construct(private readonly PushSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        $userId = $notifiable->getKey();

        if (! is_int($userId)) {
            return;
        }

        $this->sender->sendToUser($userId, $notification->toPush($notifiable));
    }
}
