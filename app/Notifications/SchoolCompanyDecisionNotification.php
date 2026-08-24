<?php

namespace App\Notifications;

use App\Domain\SchoolTransport\Models\SchoolCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The administrator's decision, delivered to the company that registered.
 *
 * Approval is what makes the company exist to families at all, so the owner
 * must hear it the moment it happens — and a rejection carries its reason,
 * because "no" without a why cannot be fixed and resubmitted.
 */
class SchoolCompanyDecisionNotification extends Notification
{
    use Queueable;

    /** @param 'approved'|'rejected'|'suspended' $event */
    public function __construct(
        public string $event,
        public string $companyName,
        public ?string $reason = null,
    ) {}

    public static function forCompany(SchoolCompany $company, string $event): self
    {
        return new self($event, $company->name, $company->rejection_reason);
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
            'tag' => 'school-company:'.$this->companyName,
            'url' => '/admin',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'school_company',
            'title' => __('notifications.school_company_'.$this->event.'_title'),
            'body' => __('notifications.school_company_'.$this->event.'_body', [
                'name' => $this->companyName,
                'reason' => $this->reason ?? '',
            ]),
            'event' => $this->event,
        ];
    }
}
