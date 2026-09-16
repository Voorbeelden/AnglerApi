<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Zorgt dat elke API-response (v1) dezelfde vorm heeft, ongeacht welke
 * controller of resource - belangrijk zodra de mobiele app (MAUI)
 * hierop gebouwd wordt: één voorspelbare structuur om overal tegen te
 * deserialiseren, in plaats van per endpoint een andere vorm te moeten
 * uitzoeken.
 *
 * Succes:
 *   { "success": true, "data": ..., "message": "...", "meta": {...}? }
 * Fout:
 *   { "success": false, "message": "...", "errors": {...}? }
 */
trait ApiResponses
{
    protected function ok($data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(array_filter([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], fn ($v) => $v !== null), $status);
    }

    protected function created($data = null, ?string $message = null): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    protected function noContentOk(): JsonResponse
    {
        return response()->json(['success' => true], 204);
    }

    /**
     * Voor gepagineerde lijsten: data en meta (huidige pagina, totaal, ...)
     * apart, zodat de mobiele app zelf "laad meer"/infinite-scroll kan
     * implementeren zonder de paginate-links te moeten parsen.
     */
    protected function paginatedOk(LengthAwarePaginator $paginator, ?string $resourceClass = null): JsonResponse
    {
        $items = $resourceClass
            ? $resourceClass::collection($paginator->items())
            : $paginator->items();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    protected function fail(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], fn ($v) => $v !== null), $status);
    }

    protected function notFound(?string $message = null): JsonResponse
    {
        return $this->fail($message ?? __('app.api_not_found'), 404);
    }

    protected function forbidden(?string $message = null): JsonResponse
    {
        return $this->fail($message ?? __('app.api_forbidden'), 403);
    }
}
