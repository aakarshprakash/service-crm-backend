<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /** Standard envelope: { data, meta, message }. */
    protected function ok(mixed $data = null, string $message = '', int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(array_filter([
            'data' => $data,
            'meta' => $meta ?: null,
            'message' => $message ?: null,
        ], fn ($v) => $v !== null), $status);
    }

    protected function created(mixed $data, string $message = 'Created successfully.'): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    protected function paginated(LengthAwarePaginator $paginator, ?callable $map = null, array $extraMeta = []): JsonResponse
    {
        $items = $map ? collect($paginator->items())->map($map)->values() : $paginator->items();

        return response()->json([
            'data' => $items,
            'meta' => array_merge([
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ], $extraMeta),
        ]);
    }

    /** ?per_page= bounded to protect the database (NFR: max 50 per page). */
    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(50, (int) $request->integer('per_page', $default)));
    }

    /** Escape LIKE wildcards in user search input. */
    protected function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($term)).'%';
    }
}
