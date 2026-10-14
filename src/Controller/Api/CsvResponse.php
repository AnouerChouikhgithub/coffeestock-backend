<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\Response;

final class CsvResponse
{
    /**
     * @param array<string, string> $headers
     */
    public static function make(string $body, array $headers = []): Response
    {
        $response = new Response($body, Response::HTTP_OK, array_merge([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="export.csv"',
        ], $headers));

        return $response;
    }
}
