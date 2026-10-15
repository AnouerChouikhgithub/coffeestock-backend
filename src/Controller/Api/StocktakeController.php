<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Stocktake;
use App\Enum\StocktakeStatus;
use App\Repository\StocktakeCountRepository;
use App\Repository\StocktakeRepository;
use App\Service\StocktakeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stocktake workflow (REQ-051/052): create DRAFT with counts, read, commit.
 * Commit converts each non-zero delta into a STOCKTAKE_DELTA movement atomically.
 */
final class StocktakeController extends AbstractController
{
    use ActorTrait;

    public function __construct(
        private readonly StocktakeService $stocktakeService,
        private readonly StocktakeRepository $repository,
        private readonly StocktakeCountRepository $counts,
    ) {
    }

    #[Route('/api/stocktakes', name: 'api_stocktakes_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = QueryParamsHelper::jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $rawCounts = $body['counts'] ?? null;
        if (!\is_array($rawCounts)) {
            return ErrorResponse::validationFailed([['field' => 'counts', 'issue' => 'must be an array (REQ-051)']]);
        }

        $counts = [];
        foreach ($rawCounts as $raw) {
            $counts[] = [
                'material_id' => (int) ($raw['material_id'] ?? 0),
                'counted_quantity' => (string) ($raw['counted_quantity'] ?? ''),
            ];
        }

        $result = $this->stocktakeService->createDraft($counts, $this->userOrNull());
        if ($result instanceof Stocktake) {
            return $this->json($this->resource($result), 201);
        }

        return self::errorToResponse($result);
    }

    #[Route('/api/stocktakes/{id}', name: 'api_stocktakes_read', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function read(int $id): JsonResponse
    {
        $stocktake = $this->repository->find($id);
        if (!$stocktake instanceof Stocktake) {
            return ErrorResponse::notFound('Stocktake not found.');
        }

        return $this->json($this->resource($stocktake));
    }

    #[Route('/api/stocktakes/{id}/commit', name: 'api_stocktakes_commit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function commit(Request $request, int $id): JsonResponse
    {
        $body = QueryParamsHelper::jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $approvals = [];
        $rawApprovals = $body['approvals'] ?? [];
        if (\is_array($rawApprovals)) {
            foreach ($rawApprovals as $raw) {
                $approvals[] = [
                    'count_id' => (int) ($raw['count_id'] ?? 0),
                    'approver_comment' => isset($raw['approver_comment']) ? (string) $raw['approver_comment'] : null,
                ];
            }
        }

        $result = $this->stocktakeService->commit($id, $approvals, $this->userOrNull());
        if ($result instanceof Stocktake) {
            return $this->json($this->resource($result));
        }

        $flat = implode(' ', array_column($result, 'issue'));
        if (str_contains($flat, 'Stocktake not found')) {
            return ErrorResponse::notFound('Stocktake not found.');
        }
        if (str_contains($flat, 'STOCKTAKE_NOT_DRAFT')) {
            return ErrorResponse::make('STOCKTAKE_NOT_DRAFT', 'Stocktake is not a draft.', $result, 409);
        }
        if (str_contains($flat, 'APPROVAL_REQUIRED')) {
            return ErrorResponse::make('APPROVAL_REQUIRED', 'Over-tolerance counts need an approval entry.', $result, 409);
        }
        if (str_contains($flat, 'NEGATIVE_STOCK_BLOCKED')) {
            return ErrorResponse::make('NEGATIVE_STOCK_BLOCKED', 'Delta would push stock below zero.', $result, 409);
        }

        return ErrorResponse::validationFailed($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Stocktake $stocktake): array
    {
        $counts = [];
        foreach ($this->counts->findBy(['stocktake' => $stocktake->getId()], ['id' => 'ASC']) as $count) {
            $counts[] = [
                'id' => $count->getId(),
                'material_id' => $count->getMaterial()->getId(),
                'counted_quantity' => $count->getCountedQuantity(),
                'derived_stock_at_count' => $count->getDerivedStockAtCount(),
                'delta' => $count->getDelta(),
                'reconciliation_movement_id' => $count->getReconciliationMovement()?->getId(),
            ];
        }

        return [
            'id' => $stocktake->getId(),
            'status' => StocktakeStatus::COMMITTED === $stocktake->getStatus() ? 'COMMITTED' : 'DRAFT',
            'taken_at' => QueryParamsHelper::atom($stocktake->getTakenAt()),
            'created_by_user_id' => $stocktake->getCreatedByUser()->getId(),
            'committed_by_user_id' => $stocktake->getCommittedByUser()?->getId(),
            'committed_at' => $stocktake->getCommittedAt() ? QueryParamsHelper::atom($stocktake->getCommittedAt()) : null,
            'counts' => $counts,
        ];
    }

    /**
     * @param list<array{field: string, issue: string}> $errors
     */
    private static function errorToResponse(array $errors): JsonResponse
    {
        $flat = implode(' ', array_column($errors, 'issue'));
        if (str_contains($flat, 'MATERIAL_INACTIVE')) {
            return ErrorResponse::make('MATERIAL_INACTIVE', 'Material is inactive.', $errors, 422);
        }
        if (str_contains($flat, 'Material not found')) {
            return ErrorResponse::notFound('Material not found.');
        }

        return ErrorResponse::validationFailed($errors);
    }
}
