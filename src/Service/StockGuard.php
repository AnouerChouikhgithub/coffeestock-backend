<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Material;
use App\Repository\StockMovementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * OD-1 default policy (0-floor): a stock-decreasing entry is rejected when the
 * resulting derived balance would fall below 0.000 (409 NEGATIVE_STOCK_BLOCKED),
 * except where an explicitly-flagged, approved adjustment is allowed per OD-1.
 *
 * Concurrency (phase 5 requirement): balance checks and ledger writes for one
 * material must be serialized. lockMaterial() takes a row-level FOR UPDATE lock
 * on the material inside the caller's transaction so two simultaneous
 * consumptions cannot both observe the same pre-write balance and overdraw.
 */
final class StockGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementRepository $movements,
    ) {
    }

    /**
     * Serialize concurrent writers on one material. Must be called inside an
     * open transaction; the lock is held until that transaction ends.
     */
    public function lockMaterial(int $materialId): void
    {
        $this->em->getConnection()->executeQuery(
            'SELECT id FROM material WHERE id = :id FOR UPDATE',
            ['id' => $materialId],
        );
    }

    /** Resulting balance = current derived stock + signed delta (exact scale-3 math). */
    public function resultingBalance(Material $material, string $signedDelta): string
    {
        $current = $this->movements->getStockOnHand($material->getId() ?? 0) ?? '0.000';

        return bcadd($current, $signedDelta, 3);
    }

    /** True when the entry would push derived stock below the 0.000 floor. */
    public function wouldGoNegative(Material $material, string $signedDelta): bool
    {
        return bccomp($this->resultingBalance($material, $signedDelta), '0.000', 3) < 0;
    }
}
