<?php

namespace App\Http\Middleware;

use App\Domain\Network\Models\City;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level RBAC. Accepts several permissions; any one of them grants access
 * (`permission:fleet.buses.view,fleet.buses.manage`). City scoping is enforced
 * alongside, so a Bandar Abbas operator cannot act on another city's records.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->deny($request, 'unauthenticated', 401);
        }

        $user->loadMissing('roles.permissions');

        if (! $user->hasAnyPermission(...$permissions)) {
            return $this->deny($request, 'forbidden', 403, ['required' => $permissions]);
        }

        $city = $request->attributes->get('city')
            ?? (app()->bound(City::class) ? app(City::class) : null);

        if ($city instanceof City && ! $user->canAccessCity($city)) {
            return $this->deny($request, 'city_not_permitted', 403, ['city' => $city->slug]);
        }

        return $next($request);
    }

    private function deny(Request $request, string $code, int $status, array $context = []): Response
    {
        if ($request->expectsJson()) {
            return ApiResponse::error($code, null, $status, $context);
        }

        abort($status, __('errors.'.$code));
    }
}
