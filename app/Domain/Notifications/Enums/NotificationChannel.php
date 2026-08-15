<?php

namespace App\Domain\Notifications\Enums;

use App\Support\Concerns\HasLabel;

enum NotificationChannel: string
{
    use HasLabel;

    case Push = 'push';
    case Sms = 'sms';
    case InApp = 'in_app';
}
