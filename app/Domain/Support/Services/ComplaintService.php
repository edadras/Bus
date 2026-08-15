<?php

namespace App\Domain\Support\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Support\Enums\ComplaintCategory;
use App\Domain\Support\Enums\ComplaintPriority;
use App\Domain\Support\Enums\ComplaintStatus;
use App\Domain\Support\Events\ComplaintAnswered;
use App\Domain\Support\Models\Complaint;
use App\Domain\Support\Models\ComplaintAttachment;
use App\Domain\Support\Models\ComplaintMessage;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Complaint intake, triage and the passenger-facing conversation. */
class ComplaintService
{
    private const MAX_ATTACHMENTS = 5;

    /** @param array<int, UploadedFile> $attachments */
    public function create(User $user, array $data, array $attachments = []): Complaint
    {
        return DB::transaction(function () use ($user, $data, $attachments): Complaint {
            $category = ComplaintCategory::from($data['category']);

            $complaint = Complaint::create([
                'user_id' => $user->id,
                'city_id' => $data['city_id'] ?? $user->city_id,
                'category' => $category,
                'priority' => $this->priorityFor($category),
                'status' => ComplaintStatus::New,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'trip_id' => $data['trip_id'] ?? null,
                'passenger_trip_id' => $data['passenger_trip_id'] ?? null,
                'bus_id' => $data['bus_id'] ?? null,
                'driver_id' => $data['driver_id'] ?? null,
                'bus_line_id' => $data['bus_line_id'] ?? null,
                'bus_stop_id' => $data['bus_stop_id'] ?? null,
                'wallet_transaction_id' => $data['wallet_transaction_id'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'occurred_at' => $data['occurred_at'] ?? now(),
            ]);

            ComplaintMessage::create([
                'complaint_id' => $complaint->id,
                'user_id' => $user->id,
                'author_type' => ComplaintMessage::AUTHOR_PASSENGER,
                'body' => $data['body'],
            ]);

            $this->attach($complaint, $attachments, $user);

            return $complaint->fresh(['attachments']);
        });
    }

    /** @param array<int, UploadedFile> $files */
    public function attach(
        Complaint $complaint,
        array $files,
        User $uploader,
        ?ComplaintMessage $message = null,
    ): void {
        if ($files === []) {
            return;
        }

        if ($complaint->attachments()->count() + count($files) > self::MAX_ATTACHMENTS) {
            throw DomainException::make('too_many_attachments', 422, ['maximum' => self::MAX_ATTACHMENTS]);
        }

        foreach ($files as $file) {
            // Stored on the private disk: complaint photos routinely contain
            // faces, plates and interiors and must not be publicly reachable.
            $path = $file->store("complaints/{$complaint->id}", 'local');

            ComplaintAttachment::create([
                'complaint_id' => $complaint->id,
                'complaint_message_id' => $message?->id,
                'uploaded_by' => $uploader->id,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        }
    }

    public function assign(Complaint $complaint, User $agent, User $actor): Complaint
    {
        $complaint->forceFill([
            'assigned_to' => $agent->id,
            'assigned_at' => now(),
            'status' => $complaint->status === ComplaintStatus::New
                ? ComplaintStatus::Reviewing
                : $complaint->status,
        ])->save();

        $this->systemNote($complaint, __('complaints.assigned_to', ['name' => $agent->name]));

        return $complaint;
    }

    public function reply(Complaint $complaint, User $agent, string $body, bool $internal = false, array $files = []): ComplaintMessage
    {
        return DB::transaction(function () use ($complaint, $agent, $body, $internal, $files): ComplaintMessage {
            $message = ComplaintMessage::create([
                'complaint_id' => $complaint->id,
                'user_id' => $agent->id,
                'author_type' => ComplaintMessage::AUTHOR_AGENT,
                'body' => $body,
                'is_internal' => $internal,
            ]);

            $this->attach($complaint, $files, $agent, $message);

            if (! $internal) {
                $complaint->forceFill([
                    'first_response_at' => $complaint->first_response_at ?? now(),
                    'status' => $complaint->status === ComplaintStatus::New
                        ? ComplaintStatus::InProgress
                        : $complaint->status,
                ])->save();

                // Only a passenger-visible reply is worth a notification.
                ComplaintAnswered::dispatch($complaint, $body);
            }

            return $message;
        });
    }

    public function passengerReply(Complaint $complaint, string $body, array $files = []): ComplaintMessage
    {
        if (! $complaint->status->isOpen()) {
            throw DomainException::make('complaint_closed', 422);
        }

        $message = ComplaintMessage::create([
            'complaint_id' => $complaint->id,
            'user_id' => $complaint->user_id,
            'author_type' => ComplaintMessage::AUTHOR_PASSENGER,
            'body' => $body,
        ]);

        $this->attach($complaint, $files, $complaint->user, $message);

        return $message;
    }

    public function changeStatus(Complaint $complaint, ComplaintStatus $status, User $actor, ?string $note = null): Complaint
    {
        $complaint->forceFill([
            'status' => $status,
            'resolved_at' => $status === ComplaintStatus::Resolved ? now() : $complaint->resolved_at,
            'closed_at' => $status === ComplaintStatus::Closed ? now() : $complaint->closed_at,
            'resolution_note' => $note ?? $complaint->resolution_note,
        ])->save();

        $this->systemNote($complaint, __('complaints.status_changed', ['status' => $status->label()]));

        return $complaint;
    }

    public function rate(Complaint $complaint, int $rating): Complaint
    {
        if ($complaint->status !== ComplaintStatus::Resolved && $complaint->status !== ComplaintStatus::Closed) {
            throw DomainException::make('complaint_not_rateable', 422);
        }

        $complaint->forceFill(['satisfaction_rating' => max(1, min(5, $rating))])->save();

        return $complaint;
    }

    /** Safety-related categories jump the queue automatically. */
    private function priorityFor(ComplaintCategory $category): ComplaintPriority
    {
        return match ($category) {
            ComplaintCategory::Misconduct => ComplaintPriority::Urgent,
            ComplaintCategory::Payment, ComplaintCategory::Driver => ComplaintPriority::High,
            ComplaintCategory::Technical, ComplaintCategory::BusCondition => ComplaintPriority::Normal,
            default => ComplaintPriority::Low,
        };
    }

    private function systemNote(Complaint $complaint, string $body): void
    {
        ComplaintMessage::create([
            'complaint_id' => $complaint->id,
            'author_type' => ComplaintMessage::AUTHOR_SYSTEM,
            'body' => $body,
            'is_internal' => true,
        ]);
    }
}
