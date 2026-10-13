<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Material;
use App\Enum\MovementType;
use App\Repository\MaterialRepository;
use App\Repository\StockMovementRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Inventory read model (REQ-013, REQ-070, INV-3): stock on hand is always derived
 * from the ledger, never stored.
 */
final class InventoryService
{
    public function __construct(
        private readonly MaterialRepository $materials,
        private readonly StockMovementRepository $movements,
    ) {
    }

    /**
     * @return array{material_id: int|null, name: string, base_unit: string, stock_on_hand: string, below_threshold: bool}
     */
    public function stockFor(Material $material): array
    {
        $stock = $this->movements->getStockOnHand($material->getId() ?? 0);
        $threshold = $material->getReorderThreshold();

        return [
            'material_id' => $material->getId(),
            'name' => $material->getName(),
            'base_unit' => $material->getBaseUnit()->value,
            'stock_on_hand' => self::normalizeScale($stock ?? '0.000'),
            'below_threshold' => null !== $threshold && bccomp($stock ?? '0.000', $threshold, 3) <= 0,
        ];
    }

    /**
     * @return array{page: int, perPage: int, total: int, items: list<array<string, mixed>>}
     */
    public function list(int $page, int $perPage, ?string $type, bool $belowThresholdOnly = false): array
    {
        $qb = $this->materials->createQueryBuilder('m')
            ->andWhere('m.is_active = true')
            ->orderBy('m.name', 'ASC');

        if (null !== $type) {
            $qb->andWhere('m.type = :type')->setParameter('type', $type);
        }

        $qb->setFirstResult(0)->setMaxResults(null);
        $paginator = new Paginator($qb, true);
        $total = \count($paginator);

        $items = [];
        foreach ($paginator->getIterator() as $material) {
            /** @var Material $material */
            $row = $this->stockFor($material);
            if ($belowThresholdOnly && !$row['below_threshold']) {
                --$total; // filtered out post-derivation; pagination is applied in-memory below
                continue;
            }
            $items[] = $row;
        }

        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'items' => \array_slice($items, $offset, $perPage),
        ];
    }

    /**
     * Exact scale-3 string (REQ-010): aggregates return DB numerics as strings, but the
     * scale may vary (e.g. "0.30" vs "0.300"); normalise here for wire format only.
     */
    public static function normalizeScale(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = $negative ? substr($value, 1) : $value;
        if (!str_contains($value, '.')) {
            $value .= '.000';
        } else {
            $parts = explode('.', $value);
            $value = $parts[0].'.'.str_pad($parts[1], 3, '0');
            if (\strlen($parts[1]) > 3) {
                $value = bcadd($value, '0', 3);
            }
        }

        return ($negative ? '-' : '').$value;
    }

    /**
     * @return array{page: int, perPage: int, total: int, items: list<array<string, mixed>>}|null
     */
    public function movementHistory(Material $material, int $page, int $perPage, ?string $movementType, ?string $dateFrom, ?string $dateTo): ?array
    {
        $qb = $this->movements->createQueryBuilder('m')
            ->andWhere('m.material = :material')
            ->setParameter('material', $material->getId())
            ->orderBy('m.occurred_at', 'DESC')
            ->orderBy('m.id', 'DESC');

        if (null !== $movementType) {
            $type = MovementType::tryFrom($movementType);
            if (null === $type) {
                return null; // caller turns this into 422
            }
            $qb->andWhere('m.movement_type = :mt')->setParameter('mt', $type->value);
        }

        if (null !== $dateFrom) {
            $qb->andWhere('m.occurred_at >= :from')->setParameter('from', new \DateTimeImmutable($dateFrom, new \DateTimeZone('UTC')));
        }
        if (null !== $dateTo) {
            $qb->andWhere('m.occurred_at <= :to')->setParameter('to', new \DateTimeImmutable($dateTo, new \DateTimeZone('UTC')));
        }

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
        $paginator = new Paginator($qb, true);

        $items = [];
        foreach ($paginator as $m) {
            /** @var \App\Entity\StockMovement $m */
            $items[] = [
                'id' => $m->getId(),
                'movement_type' => $m->getMovementType()->value,
                'signed_quantity' => InventoryService::normalizeScale($m->getSignedQuantity()),
                'occurred_at' => $m->getOccurredAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
                'source_document_type' => $m->getSourceDocumentType()->value,
                'source_document_id' => $m->getSourceDocumentId(),
                'created_by_user_id' => $m->getCreatedByUser()->getId(),
                'batch_id' => $m->getBatch()?->getId(),
            ];
        }

        return [
            'page' => $page,
            'perPage' => $perPage,
            'total' => \count($paginator),
            'items' => $items,
        ];
    }
}
