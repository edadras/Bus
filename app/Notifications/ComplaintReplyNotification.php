<?php

namespace App\Notifications;

use App\Domain\Support\Models\Complaint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ComplaintReplyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Complaint $complaint,
        public string $body,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'webpush'];
    }

    /** @return array<string, mixed> */
    public function toWebPush(object $notifiable): array
    {
        $payload = $this->toArray($notifiable);

        return [
            'title' => $payload['title'],
            'body' => $payload['body'],
            'tag' => 'complaint:'.$this->complaint->uuid,
            'url' => '/app/passenger',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'complaint_reply',
            'title' => __('notifications.complaint_replied_title'),
            'body' => Str::limit($this->body, 140),
            'complaint_uuid' => $this->complaint->uuid,
            'reference' => $this->complaint->reference,
        ];
    }
}
