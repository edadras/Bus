<?php

namespace App\Domain\Taxi\Events;

use App\Support\Money;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A seat freed, and — for a metered ride — the fare that was finally charged. */
class TaxiRideEnded implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $shiftId,
        public int $userId,
        public string $rideUuid,
        public int $fareAmount,
        public int $onboardCount,
        public string $status,
    ) {}

    public function broadcastOn(): array
    {
        $prefix = config('transit.live.channel_prefix');

        return [
            new PrivateChannel("$prefix.taxi.shift.{$this->shiftId}"),
            new PrivateChannel("rides.user.{$this->userId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'taxi.ride.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'ride_uuid' => $this->rideUuid,
            'fare_amount' => $this->fareAmount,
            'formatted_amount' => Money::format($this->fareAmount),
            'onboard_count' => $this->onboardCount,
            'status' => $this->status,
            'at' => now()->toIso8601String(),
        ];
    }
}
