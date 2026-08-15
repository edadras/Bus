<?php

namespace App\Http\Middleware;

use App\Domain\Network\Models\City;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Multi-city request scoping.
 *
 * The city is resolved once here and bound into the container, so no controller
 * has to remember to filter by it and no query can accidentally leak one city's
 * fleet into another's map.
 */
class ResolveTenantCity
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header('X-City')
            ?? $request->query('city')
            ?? $request->user()?->city?->slug
            ?? config('transit.default_city');

        $city = $this->lookup((string) $slug);

        if ($city === null) {
            return ApiResponse::error('unknown_city', null, 404, ['city' => $slug]);
        }

        if (! $city->is_active) {
            return ApiResponse::error('city_inactive', null, 403, ['city' => $slug]);
        }

        app()->instance(City::class, $city);
        $request->attributes->set('city', $city);

        return $next($request);
    }

    private function lookup(string $slug): ?City
    {
        return Cache::remember(
            "city:slug:$slug",
            600,
            fn () => City::where('slug', $slug)->first(),
        );
    }
}
