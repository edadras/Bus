<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The in-app notification centre.
 *
 * Every notification the platform sends is written to the `database` channel
 * regardless of whether push or SMS also fired, so this list is the complete,
 * durable record: a rider who had notifications switched off, or whose phone
 * was flat when the bus came, still finds the message here.
 *
 * Scoped to the caller in every query — a notification id is a UUID, but the
 * ownership check is what actually keeps one account out of another's inbox.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->paginate(min(50, $request->integer('per_page', 20)));

        return ApiResponse::paginated(
            $notifications->through(fn (DatabaseNotification $notification) => $this->present($notification)),
            ['unread_count' => $request->user()->unreadNotifications()->count()],
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();

        $record->markAsRead();

        return ApiResponse::success($this->present($record->refresh()));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(['unread_count' => 0]);
    }

    /**
     * Flatten the stored payload into the envelope clients render.
     *
     * The `data` column is written by each notification's toArray(), so its
     * keys vary by type; `title`/`body` are the two every type guarantees and
     * the rest is passed through for the screens that know what to do with it.
     *
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        $data = (array) $notification->data;

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? 'general',
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'data' => $data,
            'read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
