<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\OtpCode;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Mobile OTP issuing and verification.
 *
 * Codes are stored only as hashes, so a database leak does not hand an attacker
 * live credentials. Three independent limits apply: a resend cooldown, a daily
 * per-mobile cap, and a per-code attempt counter — the first two stop the SMS
 * bill and the last stops brute forcing a 5-digit code.
 */
class OtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    /** @return array{code: ?string, expires_in: int, resend_in: int} */
    public function issue(string $mobile, string $purpose = 'login', ?string $ip = null): array
    {
        $this->assertCanRequest($mobile);

        // Any earlier live code becomes invalid the moment a new one is sent,
        // so an intercepted older SMS is useless.
        OtpCode::where('mobile', $mobile)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();
        $ttl = (int) config('transit.otp.ttl_seconds');

        OtpCode::create([
            'mobile' => $mobile,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'expires_at' => now()->addSeconds($ttl),
            'request_ip' => $ip,
        ]);

        $this->sms->sendOtp($mobile, $code);

        RateLimiter::hit($this->cooldownKey($mobile), (int) config('transit.otp.resend_cooldown_seconds'));
        RateLimiter::hit($this->dailyKey($mobile), 86400);

        return [
            // The code is echoed back only where it is safe to do so, so that
            // local development and automated tests do not need an SMS gateway.
            'code' => $this->shouldRevealCode() ? $code : null,
            'expires_in' => $ttl,
            'resend_in' => (int) config('transit.otp.resend_cooldown_seconds'),
        ];
    }

    public function verify(string $mobile, string $code, string $purpose = 'login'): bool
    {
        $record = OtpCode::where('mobile', $mobile)
            ->where('purpose', $purpose)
            ->pending()
            ->latest('id')
            ->first();

        if ($record === null) {
            throw DomainException::make('otp_not_found', 422);
        }

        if ($record->isExhausted()) {
            $record->forceFill(['consumed_at' => now()])->save();

            throw DomainException::make('otp_attempts_exceeded', 429);
        }

        // A fixed development code short-circuits verification, but only when
        // explicitly configured and never in production.
        $testingCode = config('transit.otp.testing_code');

        if ($testingCode !== null && ! app()->environment('production') && hash_equals((string) $testingCode, $code)) {
            $record->forceFill(['consumed_at' => now()])->save();

            return true;
        }

        $record->increment('attempts');

        if (! Hash::check($code, $record->code_hash)) {
            throw DomainException::make('otp_invalid', 422, [
                'attempts_left' => max(0, (int) config('transit.otp.max_attempts') - $record->attempts),
            ]);
        }

        $record->forceFill(['consumed_at' => now()])->save();

        RateLimiter::clear($this->cooldownKey($mobile));

        return true;
    }

    /** Normalise Iranian mobile input to a canonical 98XXXXXXXXXX form. */
    public function normalizeMobile(string $mobile): string
    {
        // Convert Persian and Arabic-Indic digits before anything else.
        $mobile = strtr($mobile, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        return match (true) {
            str_starts_with($digits, '0098') => '98'.substr($digits, 4),
            str_starts_with($digits, '98') && strlen($digits) === 12 => $digits,
            str_starts_with($digits, '0') => '98'.substr($digits, 1),
            strlen($digits) === 10 && str_starts_with($digits, '9') => '98'.$digits,
            default => $digits,
        };
    }

    public function isValidMobile(string $normalized): bool
    {
        // Iranian mobile numbers: 98 followed by 9 and nine more digits.
        return (bool) preg_match('/^989\d{9}$/', $normalized);
    }

    private function assertCanRequest(string $mobile): void
    {
        if (RateLimiter::tooManyAttempts($this->cooldownKey($mobile), 1)) {
            throw DomainException::make('otp_cooldown', 429, [
                'retry_after' => RateLimiter::availableIn($this->cooldownKey($mobile)),
            ]);
        }

        $dailyLimit = (int) config('transit.otp.daily_request_limit');

        if (RateLimiter::tooManyAttempts($this->dailyKey($mobile), $dailyLimit)) {
            throw DomainException::make('otp_daily_limit', 429);
        }
    }

    private function generateCode(): string
    {
        $length = (int) config('transit.otp.length', 5);
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function shouldRevealCode(): bool
    {
        return app()->environment(['local', 'testing']) && config('transit.otp.testing_code') === null;
    }

    private function cooldownKey(string $mobile): string
    {
        return 'otp:cooldown:'.$mobile;
    }

    private function dailyKey(string $mobile): string
    {
        return 'otp:daily:'.$mobile;
    }
}
