<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale resolution order: explicit query parameter, then the authenticated
 * user's saved preference, then the Accept-Language header, then the app
 * default (Persian). RTL is derived from the resolved locale and shared with
 * every view.
 */
class ResolveLocale
{
    private const SUPPORTED = ['fa', 'en'];

    private const RTL = ['fa', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);

        view()->share('locale', $locale);
        view()->share('direction', in_array($locale, self::RTL, true) ? 'rtl' : 'ltr');

        $response = $next($request);

        if (method_exists($response, 'header')) {
            $response->header('Content-Language', $locale);
        }

        return $response;
    }

    private function resolve(Request $request): string
    {
        $candidates = array_filter([
            $request->query('lang'),
            $request->header('X-Locale'),
            $request->user()?->locale,
            $request->getPreferredLanguage(self::SUPPORTED),
        ]);

        foreach ($candidates as $candidate) {
            $short = substr((string) $candidate, 0, 2);

            if (in_array($short, self::SUPPORTED, true)) {
                return $short;
            }
        }

        return (string) config('app.locale', 'fa');
    }
}
