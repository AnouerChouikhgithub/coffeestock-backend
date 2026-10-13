<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Uniform error payload per docs/api-contract.md "Error format".
 * Never expose stack traces or internal detail.
 */
final class ErrorResponse
{
    /**
     * @param list<array{field: string, issue: string}> $details
     */
    public static function make(string $code, string $message, array $details = [], int $status = 400): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }

    /**
     * @param list<array{field: string, issue: string}> $fieldErrors
     */
    public static function validationFailed(array $fieldErrors = []): JsonResponse
    {
        return self::make('VALIDATION_FAILED', 'Request validation failed.', $fieldErrors, 422);
    }

    public static function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return self::make('NOT_FOUND', $message, [], 404);
    }

    public static function internalError(): JsonResponse
    {
        return self::make('INTERNAL_ERROR', 'Unexpected server error.', [], 500);
    }
}
