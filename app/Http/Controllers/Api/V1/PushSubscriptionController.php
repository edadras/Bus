<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\PushSubscription;
use App\Domain\Notifications\Services\FcmSender;
use App\Domain\Notifications\Services\WebPushSender;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Notifications\RegisterPushSubscriptionRequest;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web Push subscription management.
 *
 * The browser owns the subscription: it asks the push service for an endpoint
 * and hands it here. Our only jobs are to remember it against the signed-in
 * user and to forget it when the device unsubscribes.
 *
 * The VAPID public key is served openly on purpose — it is the identifier a
 * browser needs *before* it can subscribe, and it is public by design. The
 * private half never leaves the server.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(
        private readonly WebPushSender $web,
        private readonly FcmSender $native,
    ) {}

    /**
     * The application server key a browser passes to pushManager.subscribe().
     */
    public function key(): JsonResponse
    {
        return ApiResponse::success([
            'vapid_public_key' => $this->web->publicKey(),
            // Clients must not prompt for notification permission when push
            // is not configured on this deployment: an accepted prompt that
            // can never deliver anything is worse than no prompt at all.
            // Each client asks about the transport it can actually use.
            'enabled' => $this->web->isConfigured(),
            'web_enabled' => $this->web->isConfigured(),
            'native_enabled' => $this->native->isConfigured(),
        ]);
    }

    /**
     * Register — or refresh — this device's subscription.
     *
     * Push services re-issue endpoints, so the same device can arrive with a
     * new endpoint; and the same endpoint can arrive twice from a client that
     * re-subscribes on every launch. Keyed on (user, endpoint) with an upsert,
     * both cases converge on exactly one row.
     */
    public function store(RegisterPushSubscriptionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $subscription = PushSubscription::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
            ],
            [
                // Null for a native device: an FCM token needs no key pair.
                'public_key' => $data['keys']['p256dh'] ?? null,
                'auth_token' => $data['keys']['auth'] ?? null,
                'platform' => $data['platform'] ?? 'web',
                'device_name' => $data['device_name'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return ApiResponse::success([
            'id' => $subscription->id,
            'registered_at' => $subscription->updated_at?->toIso8601String(),
        ], status: $subscription->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Drop a subscription when the device unsubscribes or signs out.
     *
     * Scoped to the caller's own rows: an endpoint string is guessable enough
     * that an unscoped delete would let one account silence another's device.
     */
    public function destroy(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');

        abort_if($endpoint === '', 422);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $endpoint)
            ->delete();

        return ApiResponse::noContent();
    }
}
