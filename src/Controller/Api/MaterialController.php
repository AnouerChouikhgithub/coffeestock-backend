<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Material\CreateMaterialInput;
use App\Dto\Material\UpdateMaterialInput;
use App\Entity\Material;
use App\Repository\MaterialRepository;
use App\Service\MaterialService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class MaterialController extends AbstractController
{
    public function __construct(
        private readonly MaterialService $materials,
        private readonly MaterialRepository $repository,
    ) {
    }

    #[Route('/api/materials', name: 'api_materials_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = QueryParamsHelper::jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $input = new CreateMaterialInput(
            name: $body['name'] ?? '',
            type: $body['type'] ?? '',
            base_unit: $body['base_unit'] ?? '',
            reorder_threshold: $body['reorder_threshold'] ?? null,
            stocktake_threshold: $body['stocktake_threshold'] ?? null,
        );

        $result = $this->materials->create($input);
        if (\is_array($result)) {
            // Duplicate name returns the contract's DUPLICATE_NAME; other validation → VALIDATION_FAILED
            $isDuplicate = [] !== $result && ('name' === $result[0]['field'] && str_contains($result[0]['issue'], 'already exists'));

            return $isDuplicate
                ? ErrorResponse::make('DUPLICATE_NAME', 'A material with this name already exists.', $result, 422)
                : ErrorResponse::validationFailed($result);
        }

        return $this->materialResource($result, 201);
    }

    #[Route('/api/materials', name: 'api_materials_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = QueryParamsHelper::pagination($request);
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }

        $type = $request->query->get('type');
        $search = $request->query->get('search');
        $includeInactive = 'true' === $request->query->get('includeInactive', 'false');

        try {
            $result = $this->materials->list($pagination['page'], $pagination['perPage'], $type, $search, $includeInactive);
        } catch (\InvalidArgumentException $e) {
            return ErrorResponse::validationFailed([['field' => 'type', 'issue' => $e->getMessage()]]);
        }

        return $this->json([
            'page' => $pagination['page'],
            'perPage' => $pagination['perPage'],
            'total' => $result['total'],
            'items' => array_map(fn (Material $m) => $this->materialArray($m), $result['items']),
        ]);
    }

    #[Route('/api/materials/{id}', name: 'api_materials_read', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function read(int $id): JsonResponse
    {
        $material = $this->repository->find($id);
        if (!$material instanceof Material) {
            return ErrorResponse::notFound('Material not found.');
        }

        return $this->materialResource($material);
    }

    #[Route('/api/materials/{id}', name: 'api_materials_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $material = $this->repository->find($id);
        if (!$material instanceof Material) {
            return ErrorResponse::notFound('Material not found.');
        }

        $body = QueryParamsHelper::jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $dto = new UpdateMaterialInput(
            name: $body['name'] ?? null,
            type: $body['type'] ?? null,
            reorder_threshold: $body['reorder_threshold'] ?? null,
            stocktake_threshold: $body['stocktake_threshold'] ?? null,
            is_active: $body['is_active'] ?? null,
            raw: $body,
        );

        // thresholds may be explicitly nulled by the client (contract allows null)
        if (array_key_exists('reorder_threshold', $body) && null === $body['reorder_threshold']) {
            $material->setReorderThreshold(null);
        }
        if (array_key_exists('stocktake_threshold', $body) && null === $body['stocktake_threshold']) {
            $material->setStocktakeThreshold(null);
        }

        $result = $this->materials->update($material, $dto);
        if (\is_array($result)) {
            return ErrorResponse::validationFailed($result);
        }

        return $this->materialResource($result);
    }

    private function materialResource(Material $m, int $status = 200): JsonResponse
    {
        return $this->json($this->materialArray($m), $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function materialArray(Material $m): array
    {
        return [
            'id' => $m->getId(),
            'name' => $m->getName(),
            'type' => $m->getType()->value,
            'base_unit' => $m->getBaseUnit()->value,
            'reorder_threshold' => $m->getReorderThreshold(),
            'stocktake_threshold' => $m->getStocktakeThreshold(),
            'is_active' => $m->isActive(),
            'created_at' => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
