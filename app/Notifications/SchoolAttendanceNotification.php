<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "Your child is on the bus."
 *
 * The one notification on the platform a parent will read every single day, so
 * it says the child's name and the time and nothing else. The collapse tag is
 * per child per event, so two taps by a driver correcting themselves replace
 * one another rather than stacking.
 */
class SchoolAttendanceNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $studentName,
        public string $event,
        public CarbonInterface $at,
        public string $tripUuid,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    /** @return array<string, mixed> */
    public function toPush(object $notifiable): array
    {
        $payload = $this->toArray($notifiable);

        return [
            'title' => $payload['title'],
            'body' => $payload['body'],
            'tag' => 'school:'.$this->tripUuid.':'.$this->studentName.':'.$this->event,
            'url' => '/app/passenger',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'school_attendance',
            'title' => __('notifications.school_'.$this->event.'_title'),
            'body' => __('notifications.school_'.$this->event.'_body', [
                'name' => $this->studentName,
                'time' => $this->at->format('H:i'),
            ]),
            'student' => $this->studentName,
            'event' => $this->event,
            'trip_uuid' => $this->tripUuid,
        ];
    }
}
