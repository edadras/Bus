<?php

namespace App\Support\Api;

use App\Support\Exceptions\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps every exception that escapes a controller onto the stable API error
 * envelope. Internal failures never leak their message outside of debug mode.
 */
final class ExceptionRenderer
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof DomainException => ApiResponse::error(
                $e->errorCode(),
                $e->getMessage(),
                $e->httpStatus(),
                $e->context(),
            ),

            $e instanceof ValidationException => ApiResponse::error(
                'validation_failed',
                __('errors.validation_failed'),
                422,
                $e->errors(),
            ),

            $e instanceof AuthenticationException => ApiResponse::error('unauthenticated', null, 401),

            $e instanceof AuthorizationException => ApiResponse::error('forbidden', $e->getMessage() ?: null, 403),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error('not_found', null, 404),

            $e instanceof TooManyRequestsHttpException => ApiResponse::error('rate_limited', null, 429),

            $e instanceof HttpExceptionInterface => ApiResponse::error(
                'http_error',
                $e->getMessage() ?: null,
                $e->getStatusCode(),
            ),

            default => ApiResponse::error(
                'server_error',
                config('app.debug') ? $e->getMessage() : null,
                500,
                config('app.debug') ? ['exception' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine()] : [],
            ),
        };
    }
}
