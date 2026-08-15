<?php

use App\Http\Middleware\EnsureJsonResponse;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveLocale;
use App\Http\Middleware\ResolveTenantCity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureJsonResponse::class,
            ResolveLocale::class,
            ResolveTenantCity::class,
        ]);

        $middleware->web(append: [
            ResolveLocale::class,
        ]);

        $middleware->alias([
            'permission' => RequirePermission::class,
        ]);

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return App\Support\Api\ExceptionRenderer::render($e, $request);
            }

            return null;
        });
    })->create();
