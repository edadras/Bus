<?php

namespace Tests\Feature\Auth;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\OtpCode;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Network\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbac();
        $this->city = $this->makeCity();
        RateLimiter::clear('otp-ip:127.0.0.1');
    }

    public function test_a_passenger_signs_in_with_an_otp_and_receives_a_scoped_token(): void
    {
        $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567']);

        $request->assertOk()->assertJsonPath('data.mobile', '989121234567');

        $code = $request->json('data.debug_code');
        $this->assertNotNull($code, 'The testing environment must expose the code.');

        $verify = $this->postJson('/api/v1/auth/otp/verify', [
            'mobile' => '09121234567',
            'code' => $code,
            'client' => 'passenger',
        ]);

        $verify->assertOk()
            ->assertJsonPath('data.abilities', ['passenger'])
            ->assertJsonStructure(['data' => ['token', 'user' => ['uuid', 'mobile']]]);

        $this->assertDatabaseHas('users', ['mobile' => '989121234567']);
    }

    /**
     * Every client the token service can mint for must be one validation
     * accepts.
     *
     * The taxi and school driver apps were mintable by `AuthService` and
     * rejected by the form request before they ever reached it — a whole app
     * that could not sign in, invisible to every test that reached for
     * `Sanctum::actingAs` instead of the endpoint.
     */
    public function test_every_client_the_service_can_mint_for_is_one_validation_accepts(): void
    {
        $driver = Driver::factory()->create(['city_id' => $this->city->id]);
        $driver->user->forceFill(['mobile' => '989121234567'])->save();

        foreach (AuthService::CLIENTS as $client) {
            // The resend cooldown and the per-IP bucket both exist to stop a
            // burst of SMS; this loop is one. ThrottleRequests hashes the pair
            // (limiter, key), which is why the raw key alone does nothing.
            RateLimiter::clear('otp:cooldown:989121234567');
            RateLimiter::clear(md5('otp'.'otp-ip:127.0.0.1'));
            RateLimiter::clear(md5('otp'.'otp-mobile:989121234567'));

            $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '989121234567']);

            $response = $this->postJson('/api/v1/auth/otp/verify', [
                'mobile' => '989121234567',
                'code' => $request->json('data.debug_code'),
                'client' => $client,
            ]);

            // A 403 is a legitimate answer — this driver is not a merchant.
            // A 422 means validation has never heard of the client at all,
            // which is a surface nobody can reach.
            $this->assertNotSame(
                422,
                $response->status(),
                "[$client] is mintable by the service but rejected by validation.",
            );
        }
    }

    public function test_a_taxi_driver_signs_in_and_receives_a_taxi_token(): void
    {
        $driver = Driver::factory()->create(['city_id' => $this->city->id]);
        $driver->user->forceFill(['mobile' => '989121234567'])->save();

        $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '989121234567']);

        $this->postJson('/api/v1/auth/otp/verify', [
            'mobile' => '989121234567',
            'code' => $request->json('data.debug_code'),
            'client' => 'taxi_driver',
        ])->assertOk()->assertJsonPath('data.abilities', ['taxi_driver', 'passenger']);
    }

    public function test_a_school_driver_signs_in_and_receives_a_school_token(): void
    {
        $driver = Driver::factory()->create(['city_id' => $this->city->id]);
        $driver->user->forceFill(['mobile' => '989121234567'])->save();

        $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '989121234567']);

        $this->postJson('/api/v1/auth/otp/verify', [
            'mobile' => '989121234567',
            'code' => $request->json('data.debug_code'),
            'client' => 'school_driver',
        ])->assertOk()->assertJsonPath('data.abilities', ['school_driver', 'passenger']);
    }

    public function test_an_unknown_client_is_still_refused(): void
    {
        $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567']);

        $this->postJson('/api/v1/auth/otp/verify', [
            'mobile' => '09121234567',
            'code' => $request->json('data.debug_code'),
            'client' => 'operations_console',
        ])->assertStatus(422);
    }

    public function test_signing_in_creates_a_wallet_for_the_new_passenger(): void
    {
        $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567']);

        $this->postJson('/api/v1/auth/otp/verify', [
            'mobile' => '09121234567',
            'code' => $request->json('data.debug_code'),
        ])->assertOk();

        $user = User::where('mobile', '989121234567')->firstOrFail();

        $this->assertNotNull($user->wallets()->first(), 'A wallet must exist before the first payment.');
    }

    public function test_persian_digits_in_a_mobile_number_are_normalised(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => '۰۹۱۲۱۲۳۴۵۶۷'])
            ->assertOk()
            ->assertJsonPath('data.mobile', '989121234567');
    }

    public function test_a_number_that_is_too_short_fails_validation(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => '0912123'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_a_well_formed_but_non_iranian_mobile_is_rejected(): void
    {
        // Right length, wrong shape: must fail the format check, not the
        // length rule, and must never create an account.
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => '02112345678'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_mobile');

        $this->assertSame(0, OtpCode::count());
    }

    public function test_a_wrong_code_is_rejected_and_counts_against_the_attempt_limit(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567']);

        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => '09121234567', 'code' => '00000'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'otp_invalid');

        $this->assertSame(1, OtpCode::first()->attempts);
    }

    public function test_a_code_cannot_be_reused(): void
    {
        $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567']);
        $code = $request->json('data.debug_code');

        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => '09121234567', 'code' => $code])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => '09121234567', 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'otp_not_found');
    }

    public function test_only_the_code_hash_is_stored(): void
    {
        $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567']);
        $code = $request->json('data.debug_code');

        $record = OtpCode::first();

        $this->assertNotSame($code, $record->code_hash);
        $this->assertTrue(Hash::check($code, $record->code_hash));
    }

    public function test_a_passenger_cannot_obtain_a_driver_token(): void
    {
        $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567']);

        $this->postJson('/api/v1/auth/otp/verify', [
            'mobile' => '09121234567',
            'code' => $request->json('data.debug_code'),
            'client' => 'driver',
        ])->assertStatus(403)->assertJsonPath('error.code', 'client_not_permitted');
    }

    public function test_an_approved_driver_receives_the_driver_ability(): void
    {
        $user = User::factory()->create(['mobile' => '989121234567', 'city_id' => $this->city->id]);
        Driver::factory()->create(['user_id' => $user->id, 'city_id' => $this->city->id]);

        $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567']);

        $this->postJson('/api/v1/auth/otp/verify', [
            'mobile' => '09121234567',
            'code' => $request->json('data.debug_code'),
            'client' => 'driver',
        ])->assertOk()->assertJsonPath('data.abilities', ['driver', 'passenger']);
    }

    public function test_a_suspended_driver_is_refused_a_driver_token(): void
    {
        $user = User::factory()->create(['mobile' => '989121234567', 'city_id' => $this->city->id]);
        Driver::factory()->suspended()->create(['user_id' => $user->id, 'city_id' => $this->city->id]);

        $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567']);

        $this->postJson('/api/v1/auth/otp/verify', [
            'mobile' => '09121234567',
            'code' => $request->json('data.debug_code'),
            'client' => 'driver',
        ])->assertStatus(403);
    }

    public function test_a_suspended_account_cannot_sign_in(): void
    {
        User::factory()->suspended()->create(['mobile' => '989121234567']);

        $request = $this->postJson('/api/v1/auth/otp/request', ['mobile' => '09121234567']);

        $this->postJson('/api/v1/auth/otp/verify', [
            'mobile' => '09121234567',
            'code' => $request->json('data.debug_code'),
        ])->assertStatus(403)->assertJsonPath('error.code', 'account_suspended');
    }

    public function test_staff_password_login_issues_an_admin_token(): void
    {
        $user = User::factory()->withPassword()->create([
            'mobile' => '989120000001',
            'city_id' => $this->city->id,
        ]);
        $user->assignRole(Role::ADMIN, $this->city->id);

        $this->postJson('/api/v1/auth/login', [
            'mobile' => '989120000001',
            'password' => 'password',
            'client' => 'admin',
        ])->assertOk()->assertJsonPath('data.abilities', ['admin']);
    }

    public function test_login_failures_do_not_reveal_whether_an_account_exists(): void
    {
        $user = User::factory()->withPassword()->create(['mobile' => '989120000001']);
        $user->assignRole(Role::ADMIN);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'mobile' => '989120000001', 'password' => 'wrong-password', 'client' => 'admin',
        ]);

        $unknownUser = $this->postJson('/api/v1/auth/login', [
            'mobile' => '989129999999', 'password' => 'wrong-password', 'client' => 'admin',
        ]);

        $wrongPassword->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        $unknownUser->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        $this->assertSame($wrongPassword->json('error'), $unknownUser->json('error'));
    }

    public function test_a_passenger_cannot_log_in_to_the_admin_panel(): void
    {
        $user = User::factory()->withPassword()->create(['mobile' => '989121234567']);
        $user->assignRole(Role::PASSENGER);

        $this->postJson('/api/v1/auth/login', [
            'mobile' => '989121234567', 'password' => 'password', 'client' => 'admin',
        ])->assertStatus(403);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->makePassenger($this->city);
        $this->actingAsPassenger($user);

        $this->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_protected_endpoints_reject_an_anonymous_request(): void
    {
        $this->getJson('/api/v1/wallet')->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }
}
