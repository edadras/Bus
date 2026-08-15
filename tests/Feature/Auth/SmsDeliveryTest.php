<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Services\SmsSender;
use App\Domain\Notifications\Gateways\KavenegarSmsGateway;
use App\Domain\Notifications\Gateways\LogSmsGateway;
use App\Domain\Notifications\Gateways\SmsIrGateway;
use App\Domain\Notifications\Services\SmsGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SMS sits on the critical path of sign-in, so the property that matters most
 * is not that it delivers — it is that a provider being down, slow or
 * misconfigured never turns into a failed sign-in request.
 */
class SmsDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function useGateway(string $name, array $config = []): void
    {
        config(['sms.default' => $name]);

        foreach ($config as $key => $value) {
            config(["sms.gateways.$name.$key" => $value]);
        }
    }

    public function test_the_default_gateway_is_the_log_driver(): void
    {
        // A deployment with no provider contract must still sign users in.
        $this->assertInstanceOf(LogSmsGateway::class, app(SmsGatewayManager::class)->driver());
    }

    public function test_an_unknown_driver_falls_back_instead_of_breaking_sign_in(): void
    {
        config(['sms.default' => 'a-typo', 'sms.gateways.a-typo' => null]);

        $this->assertInstanceOf(LogSmsGateway::class, app(SmsGatewayManager::class)->driver());
    }

    public function test_each_configured_driver_resolves_to_its_own_class(): void
    {
        $this->useGateway('kavenegar');
        $this->assertInstanceOf(KavenegarSmsGateway::class, app(SmsGatewayManager::class)->driver());

        $this->useGateway('smsir');
        $this->assertInstanceOf(SmsIrGateway::class, app(SmsGatewayManager::class)->driver());
    }

    public function test_kavenegar_sends_a_verification_code_through_its_approved_pattern(): void
    {
        Http::fake([
            'api.kavenegar.com/*' => Http::response([
                'return' => ['status' => 200, 'message' => 'ok'],
                'entries' => [['messageid' => 123456]],
            ]),
        ]);

        $this->useGateway('kavenegar', ['api_key' => 'secret-key', 'otp_template' => 'hamsafar-otp']);

        $result = app(SmsSender::class)->sendOtp('989120000001', '123456');

        $this->assertTrue($result->delivered);
        $this->assertSame('123456', $result->messageId);

        Http::assertSent(function (Request $request) {
            // Operators filter free-text codes, so the lookup endpoint — not
            // the plain send endpoint — is what sign-in must use.
            $this->assertStringContainsString('verify/lookup.json', $request->url());
            $this->assertSame('hamsafar-otp', $request['template']);
            $this->assertSame('123456', $request['token']);
            // The provider expects a national number, not +98.
            $this->assertSame('09120000001', $request['receptor']);

            return true;
        });
    }

    public function test_kavenegar_sends_free_text_when_no_pattern_is_configured(): void
    {
        Http::fake([
            'api.kavenegar.com/*' => Http::response([
                'return' => ['status' => 200],
                'entries' => [['messageid' => 1]],
            ]),
        ]);

        $this->useGateway('kavenegar', ['api_key' => 'secret-key', 'otp_template' => null]);

        app(SmsSender::class)->sendOtp('989120000001', '123456');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sms/send.json')
            && str_contains((string) $request['message'], '123456'));
    }

    public function test_a_provider_error_is_reported_rather_than_thrown(): void
    {
        Http::fake([
            'api.kavenegar.com/*' => Http::response([
                'return' => ['status' => 418, 'message' => 'account expired'],
            ], 200),
        ]);

        $this->useGateway('kavenegar', ['api_key' => 'secret-key']);

        $result = app(SmsSender::class)->sendOtp('989120000001', '123456');

        $this->assertFalse($result->delivered);
        $this->assertSame('account expired', $result->error);
        $this->assertSame(418, $result->status);
    }

    public function test_a_network_failure_does_not_bubble_up(): void
    {
        // Provider unreachable: the user simply does not get a code and can
        // press resend. A 500 on the sign-in request would be far worse.
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->useGateway('kavenegar', ['api_key' => 'secret-key']);

        $result = app(SmsSender::class)->sendOtp('989120000001', '123456');

        $this->assertFalse($result->delivered);
        $this->assertSame('transport_error', $result->error);
    }

    public function test_a_missing_api_key_is_reported_without_calling_out(): void
    {
        Http::fake();

        $this->useGateway('kavenegar', ['api_key' => null]);

        $result = app(SmsSender::class)->sendOtp('989120000001', '123456');

        $this->assertFalse($result->delivered);
        $this->assertSame('missing_api_key', $result->error);
        Http::assertNothingSent();
    }

    public function test_smsir_uses_its_verify_endpoint_with_named_parameters(): void
    {
        Http::fake([
            'api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageId' => 99]]),
        ]);

        $this->useGateway('smsir', ['api_key' => 'secret-key', 'otp_template' => '4242']);

        $result = app(SmsSender::class)->sendOtp('989120000001', '123456');

        $this->assertTrue($result->delivered);

        Http::assertSent(function (Request $request) {
            $this->assertStringContainsString('send/verify', $request->url());
            $this->assertSame(4242, $request['templateId']);
            $this->assertSame(
                [
                    ['name' => 'code', 'value' => '123456'],
                    ['name' => 'ttl', 'value' => (string) (int) (config('transit.otp.ttl_seconds') / 60)],
                ],
                $request['parameters'],
            );
            $this->assertSame('secret-key', $request->header('X-API-KEY')[0]);

            return true;
        });
    }

    public function test_smsir_treats_its_own_status_code_as_authoritative(): void
    {
        // The provider answers HTTP 200 with a failure status in the body.
        Http::fake([
            'api.sms.ir/*' => Http::response(['status' => 0, 'message' => 'invalid line number'], 200),
        ]);

        $this->useGateway('smsir', ['api_key' => 'secret-key']);

        $result = app(SmsSender::class)->send('989120000001', 'hello');

        $this->assertFalse($result->delivered);
        $this->assertSame('invalid line number', $result->error);
    }

    public function test_the_otp_endpoint_still_succeeds_when_the_provider_fails(): void
    {
        Http::fake(['api.kavenegar.com/*' => Http::response(['return' => ['status' => 500]], 500)]);

        $this->makeCity();
        $this->useGateway('kavenegar', ['api_key' => 'secret-key']);

        // The user gets a code they can retry, not a broken sign-in screen.
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09120000001'])
            ->assertOk();
    }
}
