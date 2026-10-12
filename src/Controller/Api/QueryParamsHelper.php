<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class QueryParamsHelper
{
    /**
     * @return array{page: int, perPage: int}|JsonResponse
     */
    public static function pagination(Request $request): array|JsonResponse
    {
        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT);
        $perPage = filter_var($request->query->get('perPage', '50'), FILTER_VALIDATE_INT);

        if (false === $page || $page < 1) {
            return ErrorResponse::validationFailed([['field' => 'page', 'issue' => 'must be an integer ≥ 1']]);
        }
        if (false === $perPage || $perPage < 1 || $perPage > 100) {
            return ErrorResponse::validationFailed([['field' => 'perPage', 'issue' => 'must be an integer between 1 and 100']]);
        }

        return ['page' => $page, 'perPage' => $perPage];
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    public static function jsonBody(Request $request): array|JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        // Must decode successfully and be an object/array (scalars like 42 or "x" are malformed here).
        if (JSON_ERROR_NONE !== json_last_error() || !\is_array($body)) {
            return ErrorResponse::make('VALIDATION_FAILED', 'Malformed JSON body.', [], 400);
        }

        return $body;
    }
}
