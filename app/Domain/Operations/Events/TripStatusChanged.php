<?php

namespace App\Domain\Operations\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TripStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $tripId,
        public int $cityId,
        public string $status,
    ) {}

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
        return 'trip.status';
    }

    public function broadcastWith(): array
    {
        return [
            'trip_id' => $this->tripId,
            'status' => $this->status,
            'at' => now()->toIso8601String(),
        ];
    }
}
