<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Services\AuthService;
use App\Domain\Identity\Services\OtpService;
use App\Domain\Network\Models\City;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\LoginRequest;
use App\Http\Requests\V1\Auth\RequestOtpRequest;
use App\Http\Requests\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\V1\UserResource;
use App\Support\Api\ApiResponse;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuthService $auth,
    ) {}

    /** Step 1 of passenger/driver sign-in: send a one-time code. */
    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        $mobile = $this->otp->normalizeMobile($request->string('mobile')->toString());

        if (! $this->otp->isValidMobile($mobile)) {
            return ApiResponse::error('invalid_mobile', null, 422);
        }

        $result = $this->otp->issue($mobile, 'login', $request->ip());

        return ApiResponse::success([
            'mobile' => $mobile,
            'expires_in' => $result['expires_in'],
            'resend_in' => $result['resend_in'],
            // Only populated outside production, so the PWA and tests can run
            // without a live SMS provider.
            'debug_code' => $result['code'],
        ]);
    }

    /** Step 2: exchange a valid code for an API token. */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $mobile = $this->otp->normalizeMobile($request->string('mobile')->toString());
        $client = $request->string('client', 'passenger')->toString();

        $this->otp->verify($mobile, $request->string('code')->toString());

        $city = $request->attributes->get('city');

        $user = $this->auth->findOrCreatePassenger($mobile, $city instanceof City ? $city : null);
        $this->auth->assertCanAuthenticate($user);

        $token = $this->auth->issueToken($user, $client, $request->string('device_name')->toString() ?: null);

        return ApiResponse::success([
            'token' => $token->plainTextToken,
            'abilities' => $this->auth->abilitiesFor($user, $client),
            'user' => new UserResource($user->load(['city', 'roles', 'driver'])),
        ]);
    }

    /** Password sign-in, used by staff for the admin panel and API. */
    public function login(LoginRequest $request): JsonResponse
    {
        $mobile = $this->otp->normalizeMobile($request->string('mobile')->toString());

        $user = \App\Domain\Identity\Models\User::where('mobile', $mobile)->first();

        // Uniform failure for unknown user and wrong password, so the endpoint
        // cannot be used to enumerate which mobile numbers have accounts.
        if ($user === null || $user->password === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw DomainException::make('invalid_credentials', 401);
        }

        $this->auth->assertCanAuthenticate($user);

        $client = $request->string('client', 'admin')->toString();
        $token = $this->auth->issueToken($user, $client, $request->string('device_name')->toString() ?: null);

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        return ApiResponse::success([
            'token' => $token->plainTextToken,
            'abilities' => $this->auth->abilitiesFor($user, $client),
            'user' => new UserResource($user->load(['city', 'roles', 'driver'])),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'user' => new UserResource($request->user()->load(['city', 'roles', 'driver'])),
            'abilities' => $request->user()->currentAccessToken()?->abilities ?? [],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user(), $request->boolean('all_devices'));

        return ApiResponse::success(['logged_out' => true]);
    }
}
