<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a paginator into the frontend's exact PaginatedResponse<T> contract
 * (see src/types/common.ts): { data: T[], meta: { currentPage, perPage,
 * totalRecords, totalPages } }. Every paginated list endpoint should return
 * ApiPagination::make($paginator, SomeResource::class).
 */
class ApiPagination
{
    /**
     * @param  class-string<JsonResource>  $resourceClass
     * @return array{data: mixed, meta: array{currentPage: int, perPage: int, totalRecords: int, totalPages: int}}
     */
    public static function make(LengthAwarePaginator $paginator, string $resourceClass): array
    {
        return [
            'data' => $resourceClass::collection($paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'totalRecords' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ];
    }
}
