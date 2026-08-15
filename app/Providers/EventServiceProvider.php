<?php

namespace App\Providers;

use App\Domain\Support\Events\ComplaintAnswered;
use App\Listeners\NotifyPassengerOfComplaintReply;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ComplaintAnswered::class => [
            NotifyPassengerOfComplaintReply::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
