<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\StockAdjustment;
use App\Entity\StockMovement;
use App\Service\AdjustmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/adjustments (REQ-050). Signed delta, mandatory reason, optional
 * `approved` flag consumed by the OD-1/OD-5 policies (see AdjustmentService).
 */
final class AdjustmentController extends AbstractController
{
    use ActorTrait;

    public function __construct(
        private readonly AdjustmentService $adjustmentService,
    ) {
    }

    #[Route('/api/adjustments', name: 'api_adjustments_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = QueryParamsHelper::jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $result = $this->adjustmentService->apply(
            (int) ($body['material_id'] ?? 0),
            (string) ($body['signed_delta'] ?? ''),
            (string) ($body['reason'] ?? ''),
            isset($body['recorded_at']) ? (string) $body['recorded_at'] : null,
            true === ($body['approved'] ?? false),
            $this->userOrNull(),
        );

        if (!isset($result['adjustment'])) {
            $flat = $result['issue'];
            $errors = [$result];
            if (str_contains($flat, 'Material not found')) {
                return ErrorResponse::notFound('Material not found.');
            }
            if (str_contains($flat, 'MATERIAL_INACTIVE')) {
                return ErrorResponse::make('MATERIAL_INACTIVE', 'Material is inactive.', $errors, 422);
            }
            if (str_contains($flat, 'NEGATIVE_STOCK_BLOCKED')) {
                return ErrorResponse::make('NEGATIVE_STOCK_BLOCKED', 'Adjustment would push stock below zero.', $errors, 409);
            }
            if (str_contains($flat, 'APPROVAL_REQUIRED')) {
                return ErrorResponse::make('APPROVAL_REQUIRED', 'Adjustment exceeds the stocktake threshold and needs approval.', $errors, 409);
            }

            return ErrorResponse::validationFailed($errors);
        }

        /** @var array{adjustment: StockAdjustment, movement: StockMovement, approval: \App\Entity\Approval|null} $result */
        $adjustment = $result['adjustment'];
        $movement = $result['movement'];

        return $this->json([
            'id' => $adjustment->getId(),
            'material_id' => $adjustment->getMaterial()->getId(),
            'signed_delta' => $adjustment->getSignedDelta(),
            'reason' => $adjustment->getReason(),
            'approval_id' => $result['approval']?->getId(),
            'created_by_user_id' => $adjustment->getCreatedByUser()->getId(),
            'stock_movement_id' => $movement->getId(),
        ], 201);
    }
}
