<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force JSON negotiation on the API. Without this, a client that forgets the
 * Accept header gets an HTML error page it cannot parse, which turns a clear
 * 422 into a mystery.
 */
class EnsureJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
