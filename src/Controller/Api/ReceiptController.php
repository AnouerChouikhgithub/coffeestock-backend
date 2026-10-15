<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Receipt;
use App\Repository\ReceiptRepository;
use App\Service\Quantity;
use App\Service\ReceiptService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ReceiptController extends AbstractController
{
    public function __construct(
        private readonly ReceiptService $receiptService,
        private readonly ReceiptRepository $repository,
    ) {
    }

    #[Route('/api/receipts', name: 'api_receipts_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = QueryParamsHelper::jsonBody($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $rawItems = $body['items'] ?? [];
        $items = [];
        if (\is_array($rawItems)) {
            foreach ($rawItems as $raw) {
                // isset() already excludes null, so a present value is a real scalar.
                $items[] = [
                    'material_id' => (int) ($raw['material_id'] ?? 0),
                    'quantity' => (string) ($raw['quantity'] ?? ''),
                    'unit_cost' => isset($raw['unit_cost']) ? (string) $raw['unit_cost'] : null,
                    'batch' => isset($raw['batch']) && \is_array($raw['batch']) ? [
                        'batch_number' => (string) ($raw['batch']['batch_number'] ?? ''),
                        'expires_at' => isset($raw['batch']['expires_at']) ? (string) $raw['batch']['expires_at'] : null,
                    ] : null,
                ];
            }
        }

        $result = $this->receiptService->create(
            (int) ($body['supplier_id'] ?? 0),
            (string) ($body['delivery_date'] ?? ''),
            $items,
            isset($body['note']) ? (string) $body['note'] : null,
        );

        if (\is_array($result)) {
            $issues = array_column($result, 'issue');
            $firstField = $result[0]['field'] ?? '';
            if ('Supplier not found.' === ($result[0]['issue'] ?? '')) {
                return ErrorResponse::notFound('Supplier not found.');
            }
            if (str_contains(implode(' ', $issues), 'SUPPLIER_INACTIVE')) {
                return ErrorResponse::make('SUPPLIER_INACTIVE', 'Supplier is inactive.', $result, 422);
            }
            if (str_contains(implode(' ', $issues), 'MATERIAL_INACTIVE')) {
                return ErrorResponse::make('MATERIAL_INACTIVE', 'Material is inactive.', $result, 422);
            }
            if (str_starts_with($firstField, 'items.')) {
                // per-item failures (quantity/batch/material) surface as VALIDATION_FAILED
                return ErrorResponse::validationFailed($result);
            }

            return ErrorResponse::validationFailed($result);
        }

        return $this->json($this->receiptArray($result), 201);
    }

    #[Route('/api/receipts', name: 'api_receipts_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = QueryParamsHelper::pagination($request);
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }

        $qb = $this->repository->createQueryBuilder('r')
            ->orderBy('r.delivery_date', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        if (null !== ($supplierId = $request->query->get('supplier_id'))) {
            if (false === filter_var($supplierId, FILTER_VALIDATE_INT)) {
                return ErrorResponse::validationFailed([['field' => 'supplier_id', 'issue' => 'must be an integer']]);
            }
            $qb->andWhere('r.supplier = :sid')->setParameter('sid', (int) $supplierId);
        }
        if (null !== ($materialId = $request->query->get('material_id'))) {
            if (false === filter_var($materialId, FILTER_VALIDATE_INT)) {
                return ErrorResponse::validationFailed([['field' => 'material_id', 'issue' => 'must be an integer']]);
            }
            $qb->andWhere('EXISTS (SELECT ri.id FROM App\Entity\ReceiptItem ri WHERE ri.receipt = r AND ri.material = :mid)')
                ->setParameter('mid', (int) $materialId);
        }
        foreach ([['date_from', 'df', '>='], ['date_to', 'dt', '<=']] as [$param, $binding, $op]) {
            $raw = $request->query->get($param);
            if (null === $raw) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $raw, new \DateTimeZone('UTC'));
            if (false === $date || $date->format('Y-m-d') !== $raw) {
                return ErrorResponse::validationFailed([['field' => $param, 'issue' => 'must be a valid YYYY-MM-DD date']]);
            }
            $qb->andWhere('r.delivery_date '.$op.' :'.$binding)->setParameter($binding, $date);
        }

        $qb->setFirstResult(($pagination['page'] - 1) * $pagination['perPage'])->setMaxResults($pagination['perPage']);
        $receipts = $qb->getQuery()->getResult();

        $items = [];
        foreach ($receipts as $receipt) {
            /* @var Receipt $receipt */
            $items[] = [
                'id' => $receipt->getId(),
                'supplier_id' => $receipt->getSupplier()->getId(),
                'delivery_date' => $receipt->getDeliveryDate()->format('Y-m-d'),
                'created_at' => QueryParamsHelper::atom($receipt->getCreatedAt()),
                'created_by_user_id' => $receipt->getCreatedByUser()->getId(),
                'item_count' => \count($receipt->getItems()),
            ];
        }

        // total: same filters, no pagination
        $totalQb = clone $qb;
        $totalQb->resetDQLPart('orderBy')->setFirstResult(null)->setMaxResults(null);
        $total = \count($totalQb->getQuery()->getResult());

        return $this->json([
            'page' => $pagination['page'],
            'perPage' => $pagination['perPage'],
            'total' => $total,
            'items' => $items,
        ]);
    }

    #[Route('/api/receipts/{id}', name: 'api_receipts_read', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function read(int $id): JsonResponse
    {
        $receipt = $this->repository->find($id);
        if (!$receipt instanceof Receipt) {
            return ErrorResponse::notFound('Receipt not found.');
        }

        return $this->json($this->receiptArray($receipt));
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptArray(Receipt $r): array
    {
        $items = [];
        foreach ($r->getItems() as $item) {
            $items[] = [
                'id' => $item->getId(),
                'material_id' => $item->getMaterial()->getId(),
                'quantity' => Quantity::normalize($item->getQuantity()),
                'unit_cost' => null === $item->getUnitCost() ? null : Quantity::normalize($item->getUnitCost()),
                'batch_id' => $item->getBatch()?->getId(),
                'stock_movement_id' => $item->getStockMovement()->getId(),
            ];
        }

        return [
            'id' => $r->getId(),
            'supplier_id' => $r->getSupplier()->getId(),
            'delivery_date' => $r->getDeliveryDate()->format('Y-m-d'),
            'note' => $r->getNote(),
            'created_at' => QueryParamsHelper::atom($r->getCreatedAt()),
            'created_by_user_id' => $r->getCreatedByUser()->getId(),
            'items' => $items,
        ];
    }
}
