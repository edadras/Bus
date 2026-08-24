<?php

namespace App\Domain\Taxi\Events;

use App\Domain\Taxi\Models\TaxiRide;
use App\Support\Money;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the driver a fare has landed.
 *
 * This is the whole point of the shared-taxi product from the driver's side:
 * money arrives in a wallet they cannot see being credited, so the app has to
 * say so at the moment it happens. The passenger is not named — the driver
 * needs the amount and the running total, not who paid.
 */
class TaxiFarePaid implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $shiftId,
        public int $driverId,
        public string $rideUuid,
        public int $amount,
        public int $netAmount,
        public int $onboardCount,
        public int $shiftGross,
    ) {}

    public static function fromRide(TaxiRide $ride, int $onboardCount, int $shiftGross): self
    {
        return new self(
            shiftId: $ride->taxi_shift_id,
            driverId: $ride->driver_id,
            rideUuid: $ride->uuid,
            amount: $ride->fare_amount,
            netAmount: $ride->fare_amount - $ride->commission_amount,
            onboardCount: $onboardCount,
            shiftGross: $shiftGross,
        );
    }

    public function broadcastOn(): array
    {
        $prefix = config('transit.live.channel_prefix');

        return [new PrivateChannel("$prefix.taxi.shift.{$this->shiftId}")];
    }

    public function broadcastAs(): string
    {
        return 'taxi.fare.paid';
    }

    public function broadcastWith(): array
    {
        return [
            'ride_uuid' => $this->rideUuid,
            'amount' => $this->amount,
            'formatted_amount' => Money::format($this->amount),
            'net_amount' => $this->netAmount,
            'onboard_count' => $this->onboardCount,
            'shift_gross' => $this->shiftGross,
            'at' => now()->toIso8601String(),
        ];
    }
}
