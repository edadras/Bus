<?php

namespace App\Domain\Wallet\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Pushes a new balance to the owner's own devices, and nowhere else. */
class WalletBalanceChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public int $balance,
        public string $transactionType,
        public int $amount,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('wallet.user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'wallet.balance';
    }

    public function broadcastWith(): array
    {
        return [
            'balance' => $this->balance,
            'type' => $this->transactionType,
            'amount' => $this->amount,
            'at' => now()->toIso8601String(),
        ];
    }
}
