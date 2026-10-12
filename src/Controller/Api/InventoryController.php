<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Material;
use App\Repository\MaterialRepository;
use App\Service\InventoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InventoryController extends AbstractController
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly MaterialRepository $materials,
    ) {
    }

    #[Route('/api/inventory', name: 'api_inventory_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = QueryParamsHelper::pagination($request);
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }

        $type = $request->query->get('type');
        if (null !== $type && !in_array($type, ['RAW', 'CONSUMABLE'], true)) {
            return ErrorResponse::validationFailed([['field' => 'type', 'issue' => 'must be RAW or CONSUMABLE']]);
        }
        $below = 'true' === $request->query->get('belowThresholdOnly', 'false');

        $result = $this->inventory->list($pagination['page'], $pagination['perPage'], $type, $below);

        return $this->json($result);
    }

    #[Route('/api/inventory/{materialId}', name: 'api_inventory_read', methods: ['GET'], requirements: ['materialId' => '\d+'])]
    public function read(int $materialId): JsonResponse
    {
        $material = $this->materials->find($materialId);
        if (!$material instanceof Material) {
            return ErrorResponse::notFound('Material not found.');
        }

        return $this->json($this->inventory->stockFor($material));
    }

    #[Route('/api/inventory/{materialId}/movements', name: 'api_inventory_movements', methods: ['GET'], requirements: ['materialId' => '\d+'])]
    public function movements(int $materialId, Request $request): JsonResponse
    {
        $material = $this->materials->find($materialId);
        if (!$material instanceof Material) {
            return ErrorResponse::notFound('Material not found.');
        }

        $pagination = QueryParamsHelper::pagination($request);
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }

        $movementType = $request->query->get('movement_type');
        if (null !== $movementType && !in_array($movementType, ['RECEIPT', 'CONSUMPTION', 'WASTE', 'ADJUSTMENT', 'STOCKTAKE_DELTA'], true)) {
            return ErrorResponse::validationFailed([
                ['field' => 'movement_type', 'issue' => 'must be one of RECEIPT, CONSUMPTION, WASTE, ADJUSTMENT, STOCKTAKE_DELTA'],
            ]);
        }

        $result = $this->inventory->movementHistory(
            $material,
            $pagination['page'],
            $pagination['perPage'],
            $movementType,
            $request->query->get('date_from'),
            $request->query->get('date_to'),
        );

        return $this->json($result ?? ['page' => 1, 'perPage' => 50, 'total' => 0, 'items' => []]);
    }
}
