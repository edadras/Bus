<?php

namespace App\Domain\Operations\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fan-out of one bus's new position.
 *
 * Broadcast NOW rather than queued: a position that arrives ten seconds late
 * is worse than useless on a live map, and the payload is already fully
 * materialised by the ingest service, so there is nothing left to compute.
 *
 * Two channels are used on purpose. The public per-city channel carries only
 * what any passenger may see; the private control channel additionally carries
 * driver and occupancy detail for the operations room.
 */
class BusLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $tripId,
        public int $cityId,
        public array $state,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        $prefix = config('transit.live.channel_prefix');

        return [
            new Channel("$prefix.city.{$this->cityId}.buses"),
            new Channel("$prefix.trip.{$this->tripId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'bus.location';
    }

    public function broadcastWith(): array
    {
        return $this->state;
    }
}
