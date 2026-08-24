<?php

namespace App\Notifications;

use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The company answered.
 *
 * A parent who has asked for a service is waiting on exactly this, and until
 * now the only way to learn the answer was to keep opening the app. One class
 * for the whole arc — accepted with a fee, rejected with a reason, given a
 * seat, billed — because to the parent they are chapters of one story.
 */
class SchoolContractNotification extends Notification
{
    use Queueable;

    /**
     * @param  'accepted'|'rejected'|'route_assigned'|'invoice_issued'  $event
     * @param  array<string, string>  $replacements
     */
    public function __construct(
        public string $event,
        public string $contractUuid,
        public array $replacements = [],
    ) {}

    public static function forContract(SchoolServiceContract $contract, string $event): self
    {
        // Loaded here rather than trusted to each caller: the billing job, the
        // company panel and the acceptance path all build this message, and a
        // relation one of them forgot is a crash in the middle of a night run.
        $contract->loadMissing(['student', 'company', 'guardian', 'route.vehicle']);

        return new self($event, $contract->uuid, [
            'name' => $contract->student?->name ?? '',
            'company' => $contract->company?->name ?? '',
            'fee' => Money::format($contract->payableAmount()),
            'reason' => $contract->rejection_reason ?? '',
            'route' => $contract->route?->name ?? '',
            'plate' => $contract->route?->vehicle?->plate ?? '',
        ]);
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
            // One decision per contract replaces the last: a parent reading
            // "accepted" after "seat assigned" has been told stale news.
            'tag' => 'school-contract:'.$this->contractUuid,
            'url' => '/app/passenger',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'school_contract',
            'title' => __('notifications.school_contract_'.$this->event.'_title'),
            'body' => __('notifications.school_contract_'.$this->event.'_body', $this->replacements),
            'event' => $this->event,
            'contract_uuid' => $this->contractUuid,
        ];
    }
}
