<?php

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Single source of truth for the API envelope. Every endpoint under /api/v1
 * answers with the same shape so clients can share one transport layer:
 *
 *   { "success": bool, "data": mixed, "meta": object|null, "error": object|null }
 */
final class ApiResponse
{
    /** @param array<string, mixed> $meta */
    public static function success(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(array_filter([
            'success' => true,
            'data' => $data,
            'meta' => $meta ?: null,
        ], static fn ($value, $key) => $key === 'success' || $key === 'data' || $value !== null, ARRAY_FILTER_USE_BOTH), $status);
    }

    /** @param array<string, mixed> $extra */
    public static function error(
        string $code,
        ?string $message = null,
        int $status = 400,
        array $extra = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'data' => null,
            'error' => array_filter([
                'code' => $code,
                'message' => $message ?? __('errors.'.$code),
                'details' => $extra ?: null,
            ], static fn ($value) => $value !== null),
        ], $status);
    }

    public static function paginated(ResourceCollection|LengthAwarePaginator $paginator, array $meta = []): JsonResponse
    {
        $page = $paginator instanceof ResourceCollection ? $paginator->resource : $paginator;

        return self::success(
            $paginator instanceof ResourceCollection ? $paginator->resolve() : $page->items(),
            array_merge([
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                    'has_more' => $page->hasMorePages(),
                ],
            ], $meta),
        );
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }
}
