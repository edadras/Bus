<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale resolution, in priority order:
 *
 *   1. ?lang= on the request      — an explicit, deliberate choice
 *   2. X-Locale header            — the same, from a native client
 *   3. the user's saved preference
 *   4. the resolved city's locale — a Persian city serves Persian
 *   5. the application default    — Persian
 *
 * Accept-Language is deliberately NOT consulted. This is a Persian-first
 * product for a specific city, and a phone that happens to be set to English
 * should not flip the whole interface — including the RTL layout — for a rider
 * standing at a Bandar Abbas bus stop. Anyone who genuinely wants English asks
 * for it explicitly, and that request wins at step 1.
 *
 * This middleware runs after ResolveTenantCity so that step 4 has a city to
 * read; and the default at step 5 comes from transit.default_locale rather
 * than app.locale, because App::setLocale() overwrites app.locale and would
 * otherwise let one English request redefine the default for every request
 * after it on the same worker.
 */
class ResolveLocale
{
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
        $supported = (array) config('transit.supported_locales', ['fa']);
        $city = $request->attributes->get('city');

        $candidates = array_filter([
            $request->query('lang'),
            $request->header('X-Locale'),
            $request->user()?->locale,
            $city?->locale,
        ]);

        foreach ($candidates as $candidate) {
            $short = substr((string) $candidate, 0, 2);

            if (in_array($short, $supported, true)) {
                return $short;
            }
        }

        return (string) config('transit.default_locale', 'fa');
    }
}
