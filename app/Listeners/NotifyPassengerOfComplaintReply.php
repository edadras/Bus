<?php

namespace App\Listeners;

use App\Domain\Support\Events\ComplaintAnswered;
use App\Notifications\ComplaintReplyNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyPassengerOfComplaintReply implements ShouldQueue
{
    public function handle(ComplaintAnswered $event): void
    {
        $event->complaint->user?->notify(
            new ComplaintReplyNotification($event->complaint, $event->messageBody),
        );
    }
}
