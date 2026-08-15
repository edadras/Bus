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
        return ['database'];
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
