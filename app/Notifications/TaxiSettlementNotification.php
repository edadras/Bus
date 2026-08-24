<?php

namespace App\Notifications;

use App\Domain\Taxi\Models\TaxiSettlement;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The answer to "where is my money".
 *
 * A driver who has requested a settlement is owed the decision, not a screen
 * they have to keep refreshing — approval, the payment reference once it is
 * transferred, or the reason it was refused.
 */
class TaxiSettlementNotification extends Notification
{
    use Queueable;

    /** @param 'approved'|'paid'|'rejected' $event */
    public function __construct(
        public string $event,
        public string $settlementUuid,
        public string $reference,
        public string $formattedNet,
        public ?string $detail = null,
    ) {}

    public static function forSettlement(TaxiSettlement $settlement, string $event): self
    {
        return new self(
            event: $event,
            settlementUuid: $settlement->uuid,
            reference: $settlement->reference,
            formattedNet: Money::format($settlement->net_amount),
            detail: $event === 'paid' ? $settlement->payment_reference : $settlement->rejection_reason,
        );
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    /** @return array<string, mixed> */
    public function toPush(object $notifiable): array
    {
        $payload = $this->toArray($notifiable);

        return [
            'title' => $payload['title'],
            'body' => $payload['body'],
            'tag' => 'taxi-settlement:'.$this->settlementUuid,
            'url' => '/app/passenger',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'taxi_settlement',
            'title' => __('notifications.taxi_settlement_'.$this->event.'_title'),
            'body' => __('notifications.taxi_settlement_'.$this->event.'_body', [
                'reference' => $this->reference,
                'amount' => $this->formattedNet,
                'detail' => $this->detail ?? '',
            ]),
            'event' => $this->event,
            'settlement_uuid' => $this->settlementUuid,
        ];
    }
}
