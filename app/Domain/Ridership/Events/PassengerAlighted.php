<?php

namespace App\Domain\Ridership\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PassengerAlighted implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $tripId,
        public int $cityId,
        public int $passengerCount,
        public string $passengerTripUuid,
    ) {}

    public function broadcastOn(): array
    {
        $prefix = config('transit.live.channel_prefix');

        return [
            new PrivateChannel("$prefix.trip.{$this->tripId}.crew"),
            new Channel("$prefix.city.{$this->cityId}.buses"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'passenger.alighted';
    }

    public function broadcastWith(): array
    {
        return [
            'trip_id' => $this->tripId,
            'passenger_count' => $this->passengerCount,
            'delta' => -1,
            'at' => now()->toIso8601String(),
        ];
    }
}
