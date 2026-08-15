<?php

namespace App\Notifications;

use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LowBalanceNotification extends Notification
{
    use Queueable;

    public function __construct(public int $balance) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'low_balance',
            'title' => __('notifications.low_balance_title'),
            'body' => __('notifications.low_balance_body', ['balance' => Money::format($this->balance)]),
            'balance' => $this->balance,
        ];
    }
}
