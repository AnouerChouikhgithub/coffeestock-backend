<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StockMovement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Ledger queries (ADR-0001 read side). Stock on hand is always derived here —
 * never stored (INV-3).
 *
 * @extends ServiceEntityRepository<StockMovement>
 */
class StockMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockMovement::class);
    }

    /**
     * stock_on_hand(material, t) = Σ signed_quantity where occurred_at ≤ t (INV-3).
     * Summing DECIMAL(13,3) scale-3 values cannot overflow numeric(38,3).
     */
    public function getStockOnHand(int $materialId, ?\DateTimeImmutable $asOf = null): ?string
    {
        $qb = $this->getOrCreateQueryBuilder()
            ->select('COALESCE(SUM(m.signed_quantity), 0) AS stock')
            ->where('m.material = :materialId')
            ->setParameter('materialId', $materialId);

        if (null !== $asOf) {
            $qb
                ->andWhere('m.occurredAt <= :asOf')
                ->setParameter('asOf', $asOf);
        }

        /** @var string|false|null $result */
        $result = $qb->getQuery()->getSingleScalarResult();

        return false === $result ? null : (string) $result;
    }

    private function getOrCreateQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('m');
    }
}
