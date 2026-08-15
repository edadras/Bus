<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Support\Enums\ComplaintStatus;
use App\Domain\Support\Models\Complaint;
use App\Domain\Support\Services\ComplaintService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ComplaintResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class SupportAdminController extends Controller
{
    public function __construct(private readonly ComplaintService $complaints) {}

    public function index(Request $request): JsonResponse
    {
        $complaints = Complaint::forCity($this->city())
            ->with(['user:id,mobile,first_name,last_name,display_name', 'assignee:id,first_name,last_name,display_name', 'line', 'bus:id,bus_number'])
            ->withCount('attachments')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->string('priority')))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($request->boolean('open_only'), fn ($q) => $q->open())
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paginated(ComplaintResource::collection($complaints));
    }

    /** Full thread, including internal notes — staff view only. */
    public function show(Complaint $complaint): JsonResponse
    {
        abort_unless($complaint->city_id === $this->city()->id, 404);

        $complaint->load([
            'messages.author:id,first_name,last_name,display_name',
            'attachments', 'user', 'assignee', 'line', 'bus', 'driver.user', 'trip',
        ]);

        return ApiResponse::success([
            'complaint' => (new ComplaintResource($complaint))->resolve(),
            'reporter' => [
                'uuid' => $complaint->user?->uuid,
                'name' => $complaint->user?->name,
                'mobile' => $complaint->user?->mobile,
            ],
            'assignee' => $complaint->assignee === null ? null : [
                'uuid' => $complaint->assignee->uuid,
                'name' => $complaint->assignee->name,
            ],
            'thread' => $complaint->messages->map(fn ($m) => [
                'id' => $m->id,
                'author_type' => $m->author_type,
                'author_name' => $m->author?->name,
                'body' => $m->body,
                'is_internal' => $m->is_internal,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'attachments' => $complaint->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
            ])->values(),
            'context' => [
                'bus_number' => $complaint->bus?->bus_number,
                'driver_name' => $complaint->driver?->user?->name,
                'line' => $complaint->line?->code,
                'trip_uuid' => $complaint->trip?->uuid,
            ],
            'sla' => [
                'first_response_minutes' => $complaint->firstResponseMinutes(),
                'resolution_minutes' => $complaint->resolutionMinutes(),
            ],
        ]);
    }

    /**
     * Who a complaint can be handed to.
     *
     * Assignment is by user UUID, and a support agent cannot reach the general
     * user lookup — nor should they, since it publishes wallet balances. This
     * answers the narrower question: which colleagues in this city handle
     * complaints. Super admins are included because they always can.
     */
    public function assignees(Request $request): JsonResponse
    {
        $city = $this->city();

        $users = User::query()
            ->whereHas('roles', function ($query) use ($city): void {
                $query->where(function ($scope) use ($city): void {
                    // A role may be granted globally or scoped to one city;
                    // both let that person work this city's queue.
                    $scope->whereNull('role_user.city_id')->orWhere('role_user.city_id', $city->id);
                })->where(function ($role): void {
                    $role->where('name', Role::SUPER_ADMIN)
                        ->orWhereHas('permissions', fn ($p) => $p->where('name', 'support.manage'));
                });
            })
            ->orderBy('first_name')
            ->limit(100)
            ->get();

        return ApiResponse::success(
            $users->map(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                // No mobile: picking a colleague from a list needs a name.
            ])->values(),
        );
    }

    public function assign(Request $request, Complaint $complaint): JsonResponse
    {
        abort_unless($complaint->city_id === $this->city()->id, 404);

        $validated = $request->validate(['user_uuid' => ['required', 'uuid', 'exists:users,uuid']]);

        $agent = User::where('uuid', $validated['user_uuid'])->firstOrFail();

        return ApiResponse::success(
            (new ComplaintResource($this->complaints->assign($complaint, $agent, $request->user())))->resolve(),
        );
    }

    public function reply(Request $request, Complaint $complaint): JsonResponse
    {
        abort_unless($complaint->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
            // Internal notes stay out of the passenger's thread entirely.
            'internal' => ['boolean'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $message = $this->complaints->reply(
            complaint: $complaint,
            agent: $request->user(),
            body: $validated['body'],
            internal: $validated['internal'] ?? false,
            files: $request->file('attachments', []),
        );

        return ApiResponse::success(['message_id' => $message->id], status: 201);
    }

    public function changeStatus(Request $request, Complaint $complaint): JsonResponse
    {
        abort_unless($complaint->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(ComplaintStatus::values())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->complaints->changeStatus(
            $complaint,
            ComplaintStatus::from($validated['status']),
            $request->user(),
            $validated['note'] ?? null,
        );

        return ApiResponse::success((new ComplaintResource($updated))->resolve());
    }

    /** Signed, short-lived URL for one attachment. */
    public function attachment(Request $request, Complaint $complaint, int $attachmentId): JsonResponse
    {
        abort_unless($complaint->city_id === $this->city()->id, 404);

        $attachment = $complaint->attachments()->findOrFail($attachmentId);

        return ApiResponse::success([
            'url' => URL::temporarySignedRoute(
                'admin.complaints.attachment.download',
                now()->addMinutes(10),
                ['complaint' => $complaint->uuid, 'attachment' => $attachment->id],
            ),
            'expires_in' => 600,
        ]);
    }
}
