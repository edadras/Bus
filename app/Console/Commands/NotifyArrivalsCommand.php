<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Models\StopArrivalSubscription;
use App\Domain\Operations\Services\EtaEngine;
use App\Notifications\BusApproachingNotification;
use Illuminate\Console\Command;

class NotifyArrivalsCommand extends Command
{
    protected $signature = 'transit:notify:arrivals';

    protected $description = 'Notify passengers whose watched bus is approaching their stop';

    public function handle(EtaEngine $eta): int
    {
        $sent = 0;

        StopArrivalSubscription::query()
            ->live()
            ->with(['stop', 'line', 'user'])
            ->chunkById(200, function ($subscriptions) use ($eta, &$sent): void {
                foreach ($subscriptions as $subscription) {
                    if (! $subscription->canNotify() || $subscription->stop === null) {
                        continue;
                    }

                    $arrivals = $eta->arrivalsForStop($subscription->stop, $subscription->bus_line_id, 3);

                    foreach ($arrivals as $arrival) {
                        $minutes = $arrival['eta']['minutes'];

                        // Only fire inside the requested window, and only for
                        // an estimate confident enough to be worth acting on.
                        if ($minutes > $subscription->notify_minutes_before || ! $arrival['eta']['reliable']) {
                            continue;
                        }

                        $subscription->user?->notify(new BusApproachingNotification(
                            stopName: $subscription->stop->name,
                            lineCode: (string) $arrival['line_code'],
                            minutes: $minutes,
                        ));

                        $subscription->forceFill(['last_notified_at' => now()])->save();
                        $sent++;

                        break;
                    }
                }
            });

        $this->info("Sent $sent arrival notification(s).");

        return self::SUCCESS;
    }
}
