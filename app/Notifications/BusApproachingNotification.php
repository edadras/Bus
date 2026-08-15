<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BusApproachingNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $stopName,
        public string $lineCode,
        public int $minutes,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    /**
     * The push payload, consumed by the `push` handler in the service worker.
     *
     * The tag collapses repeats: a rider subscribed to the same stop should
     * see the arrival estimate update in place, not accumulate a stack of
     * near-identical banners as the bus closes in.
     *
     * @return array<string, mixed>
     */
    public function toPush(object $notifiable): array
    {
        $payload = $this->toArray($notifiable);

        return [
            'title' => $payload['title'],
            'body' => $payload['body'],
            'tag' => 'arrival:'.$this->lineCode.':'.$this->stopName,
            'url' => '/app/passenger',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bus_approaching',
            'title' => __('notifications.bus_approaching_title'),
            'body' => __('notifications.bus_approaching_body', [
                'line' => $this->lineCode,
                'minutes' => $this->minutes,
                'stop' => $this->stopName,
            ]),
            'line_code' => $this->lineCode,
            'stop_name' => $this->stopName,
            'minutes' => $this->minutes,
        ];
    }
}
