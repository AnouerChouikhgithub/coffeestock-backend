<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ConsumptionEntry;
use App\Entity\StockMovement;
use App\Entity\WasteEntry;
use App\Service\Quantity;
use App\Service\UsageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/consumptions and POST /api/wastes (REQ-040/041). Identical shapes;
 * waste carries a distinct ledger type and requires a reason note (BR-15).
 */
final class UsageController extends AbstractController
{
    public function __construct(
        private readonly UsageService $usageService,
    ) {
    }

    #[Route('/api/consumptions', name: 'api_consumptions_create', methods: ['POST'])]
    public function consumption(Request $request): JsonResponse
    {
        return $this->record($request, false);
    }

    #[Route('/api/wastes', name: 'api_wastes_create', methods: ['POST'])]
    public function waste(Request $request): JsonResponse
    {
        return $this->record($request, true);
    }

    private function record(Request $request, bool $isWaste): JsonResponse
    {
        $body = QueryParamsHelper::jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $result = $this->usageService->record(
            (int) ($body['material_id'] ?? 0),
            (string) ($body['quantity'] ?? ''),
            isset($body['recorded_at']) ? (string) $body['recorded_at'] : null,
            isset($body['note']) ? (string) $body['note'] : null,
            $isWaste,
        );

        if (!isset($result['entry'])) {
            // Service returned a single {field, issue} error.
            return self::errorToResponse($result);
        }

        /** @var array{entry: ConsumptionEntry|WasteEntry, movement: StockMovement} $result */
        $entry = $result['entry'];
        $movement = $result['movement'];

        return $this->json([
            'id' => $entry->getId(),
            'material_id' => $entry->getMaterial()->getId(),
            'quantity' => Quantity::normalize($entry->getQuantity()),
            'recorded_at' => QueryParamsHelper::atom($entry->getRecordedAt()),
            'note' => $entry->getNote(),
            'created_by_user_id' => $entry->getCreatedByUser()->getId(),
            'stock_movement_id' => $movement->getId(),
        ], 201);
    }

    /**
     * @param array{field: string, issue: string} $error
     */
    private static function errorToResponse(array $error): JsonResponse
    {
        $errors = [$error];
        $flat = $error['issue'];
        if (str_contains($flat, 'Material not found')) {
            return ErrorResponse::notFound('Material not found.');
        }
        if (str_contains($flat, 'MATERIAL_INACTIVE')) {
            return ErrorResponse::make('MATERIAL_INACTIVE', 'Material is inactive.', $errors, 422);
        }
        if (str_contains($flat, 'NEGATIVE_STOCK_BLOCKED')) {
            return ErrorResponse::make('NEGATIVE_STOCK_BLOCKED', 'Entry would push stock below zero.', $errors, 409);
        }

        return ErrorResponse::validationFailed($errors);
    }
}
