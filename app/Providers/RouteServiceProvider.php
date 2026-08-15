<?php

namespace App\Providers;

use App\Support\Api\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Generous default for read-heavy map polling.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // OTP requests are expensive (they send SMS) and are a common abuse
        // vector, so they are limited by IP as well as by mobile number.
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(3)->by('otp-ip:'.$request->ip()),
            Limit::perHour(10)->by('otp-mobile:'.$request->input('mobile')),
        ]);

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Anything that moves money.
        RateLimiter::for('financial', fn (Request $request) => [
            Limit::perMinute((int) config('wallet.fraud.max_payments_per_minute'))
                ->by('fin:'.($request->user()?->id ?: $request->ip())),
            Limit::perHour((int) config('wallet.fraud.max_payments_per_hour'))
                ->by('fin-h:'.($request->user()?->id ?: $request->ip())),
        ]);

        // The GPS firehose: one bus reports many times a minute, and a burst
        // after a connectivity gap is normal, so this is intentionally high.
        RateLimiter::for('telemetry', fn (Request $request) => Limit::perMinute(60)
            ->by('gps:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perHour(30)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
