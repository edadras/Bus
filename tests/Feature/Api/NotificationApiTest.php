<?php

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\PushSubscription;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Notifications\LowBalanceNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The notification surface: the in-app inbox every user shares, and the Web
 * Push enrolment that sits beside it.
 *
 * The inbox is the durable record — a rider whose phone was off still finds
 * the message — so the ownership checks here matter as much as the delivery.
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->user = $this->makePassenger($this->city);
    }

    public function test_the_inbox_lists_a_users_own_notifications_with_an_unread_count(): void
    {
        $this->user->notify(new LowBalanceNotification(5_000));
        $this->user->notify(new LowBalanceNotification(2_000));

        $response = $this->actingAsPassenger($this->user)
            ->getJson('/api/v1/notifications')
            ->assertOk();

        $response->assertJsonPath('meta.unread_count', 2);
        $this->assertCount(2, $response->json('data'));
        $this->assertSame('low_balance', $response->json('data.0.type'));
        $this->assertFalse($response->json('data.0.read'));
        $this->assertNotNull($response->json('data.0.title'));
    }

    public function test_the_inbox_never_leaks_another_users_notifications(): void
    {
        $stranger = $this->makePassenger($this->city);
        $stranger->notify(new LowBalanceNotification(1_000));

        $this->actingAsPassenger($this->user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_another_users_notification_cannot_be_marked_read(): void
    {
        $stranger = $this->makePassenger($this->city);
        $stranger->notify(new LowBalanceNotification(1_000));

        $id = $stranger->notifications()->first()->id;

        $this->actingAsPassenger($this->user)
            ->postJson("/api/v1/notifications/{$id}/read")
            ->assertNotFound();

        $this->assertNull($stranger->notifications()->first()->read_at);
    }

    public function test_marking_read_clears_the_unread_count(): void
    {
        $this->user->notify(new LowBalanceNotification(5_000));
        $id = $this->user->notifications()->first()->id;

        $this->actingAsPassenger($this->user)
            ->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true);

        $this->actingAsPassenger($this->user)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_read_all_marks_every_notification(): void
    {
        $this->user->notify(new LowBalanceNotification(5_000));
        $this->user->notify(new LowBalanceNotification(4_000));

        $this->actingAsPassenger($this->user)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(0, $this->user->unreadNotifications()->count());
    }

    public function test_the_inbox_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    // ── Web Push enrolment ────────────────────────────────────────────────

    public function test_the_vapid_key_endpoint_reports_when_push_is_not_configured(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

        $this->getJson('/api/v1/push/key')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
    }

    public function test_the_vapid_key_endpoint_publishes_only_the_public_half(): void
    {
        config([
            'webpush.vapid.public_key' => 'public-half',
            'webpush.vapid.private_key' => 'private-half',
        ]);

        $response = $this->getJson('/api/v1/push/key')->assertOk();

        $response->assertJsonPath('data.enabled', true);
        $response->assertJsonPath('data.vapid_public_key', 'public-half');
        $this->assertStringNotContainsString('private-half', $response->getContent());
    }

    public function test_a_device_can_register_a_push_subscription(): void
    {
        $this->actingAsPassenger($this->user)
            ->postJson('/api/v1/push/subscriptions', $this->subscriptionPayload())
            ->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->user->id,
            'endpoint' => 'https://push.example.com/endpoint-a',
            'platform' => 'web',
        ]);
    }

    public function test_registering_the_same_endpoint_twice_keeps_exactly_one_row(): void
    {
        $this->actingAsPassenger($this->user)
            ->postJson('/api/v1/push/subscriptions', $this->subscriptionPayload())
            ->assertCreated();

        $this->actingAsPassenger($this->user)
            ->postJson('/api/v1/push/subscriptions', $this->subscriptionPayload(['keys' => [
                'p256dh' => 'rotated-key',
                'auth' => 'rotated-auth',
            ]]))
            ->assertOk();

        $this->assertSame(1, PushSubscription::where('user_id', $this->user->id)->count());
        $this->assertSame('rotated-key', PushSubscription::first()->public_key);
    }

    public function test_a_subscription_belonging_to_someone_else_cannot_be_deleted(): void
    {
        $stranger = $this->makePassenger($this->city);

        PushSubscription::create([
            'user_id' => $stranger->id,
            'endpoint' => 'https://push.example.com/endpoint-a',
            'public_key' => 'k',
            'auth_token' => 'a',
        ]);

        $this->actingAsPassenger($this->user)
            ->deleteJson('/api/v1/push/subscriptions?endpoint='.urlencode('https://push.example.com/endpoint-a'))
            ->assertNoContent();

        // Silencing another account's device must not be possible.
        $this->assertSame(1, PushSubscription::where('user_id', $stranger->id)->count());
    }

    public function test_a_device_can_remove_its_own_subscription(): void
    {
        $this->actingAsPassenger($this->user)
            ->postJson('/api/v1/push/subscriptions', $this->subscriptionPayload())
            ->assertCreated();

        $this->actingAsPassenger($this->user)
            ->deleteJson('/api/v1/push/subscriptions?endpoint='.urlencode('https://push.example.com/endpoint-a'))
            ->assertNoContent();

        $this->assertSame(0, PushSubscription::where('user_id', $this->user->id)->count());
    }

    public function test_a_plain_http_endpoint_is_rejected(): void
    {
        $this->actingAsPassenger($this->user)
            ->postJson('/api/v1/push/subscriptions', $this->subscriptionPayload([
                'endpoint' => 'http://push.example.com/endpoint-a',
            ]))
            ->assertStatus(422);
    }

    public function test_notifications_are_sent_over_both_the_inbox_and_web_push(): void
    {
        Notification::fake();

        $this->user->notify(new LowBalanceNotification(5_000));

        Notification::assertSentTo($this->user, LowBalanceNotification::class, function ($notification, array $channels) {
            return in_array('database', $channels, true) && in_array('webpush', $channels, true);
        });
    }

    public function test_the_push_payload_carries_a_title_body_and_collapse_tag(): void
    {
        $payload = (new LowBalanceNotification(5_000))->toWebPush($this->user);

        $this->assertArrayHasKey('title', $payload);
        $this->assertArrayHasKey('body', $payload);
        // Without a tag, repeated low-balance alerts stack up as separate
        // banners instead of replacing one another.
        $this->assertSame('low_balance', $payload['tag']);
    }

    /** @param array<string, mixed> $overrides */
    private function subscriptionPayload(array $overrides = []): array
    {
        return array_merge([
            'endpoint' => 'https://push.example.com/endpoint-a',
            'keys' => ['p256dh' => 'a-public-key', 'auth' => 'an-auth-token'],
            'platform' => 'web',
            'device_name' => 'Test Browser',
        ], $overrides);
    }
}
