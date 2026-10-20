<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Approval;
use App\Entity\Material;
use App\Entity\StockMovement;
use App\Entity\Stocktake;
use App\Entity\StocktakeCount;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\MovementType;
use App\Enum\SourceDocumentType;
use App\Enum\StocktakeStatus;
use App\Repository\MaterialRepository;
use App\Repository\StocktakeCountRepository;
use App\Repository\StocktakeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stocktakes (REQ-051/052). POST creates a DRAFT with counts; the server
 * snapshots derived stock per material at creation and stores delta =
 * counted − derived (domain model). POST /{id}/commit converts each non-zero
 * delta into one STOCKTAKE_DELTA movement, atomically (INV-10).
 *
 * OD-5 tolerance: per-material stocktake_threshold when set, else the default
 * 2% of the snapshot derived stock. Over-tolerance counts require an approval
 * entry in the commit request else 409 APPROVAL_REQUIRED. Approver-role
 * enforcement ships in Phase 9; until then the acting system user is accepted.
 *
 * @phpstan-type Error array{field: string, issue: string}
 * @phpstan-type CountInput array{material_id: int, counted_quantity: string}
 * @phpstan-type ApprovalInput array{count_id: int, approver_comment: string|null}
 */
final class StocktakeService
{
    public const DEFAULT_TOLERANCE_RATE = '0.02';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialRepository $materials,
        private readonly StocktakeRepository $stocktakes,
        private readonly StocktakeCountRepository $counts,
        private readonly StockGuard $guard,
        private readonly SystemUserProvider $systemUser,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param list<CountInput> $counts
     *
     * @return Stocktake|list<Error>
     */
    public function createDraft(array $counts, ?User $actor = null): Stocktake|array
    {
        if ([] === $counts) {
            return [['field' => 'counts', 'issue' => 'must contain at least one count (REQ-051)']];
        }

        $actor = $actor ?? $this->systemUser->get();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->em->getConnection()->beginTransaction();
        try {
            $stocktake = new Stocktake($now, $actor);
            $this->em->persist($stocktake);

            $seen = [];
            foreach ($counts as $i => $count) {
                $prefix = 'counts.'.($i + 1);
                $material = $this->materials->find((int) $count['material_id']);
                if (!$material instanceof Material) {
                    $this->em->getConnection()->rollBack();

                    return [['field' => $prefix.'.material_id', 'issue' => 'Material not found.']];
                }
                if (!$material->isActive()) {
                    $this->em->getConnection()->rollBack();

                    return [['field' => $prefix.'.material_id', 'issue' => 'Material is inactive (MATERIAL_INACTIVE).']];
                }
                if (isset($seen[$material->getId()])) {
                    $this->em->getConnection()->rollBack();

                    return [['field' => $prefix.'.material_id', 'issue' => 'duplicate material within one stocktake']];
                }
                $seen[$material->getId()] = true;

                if (!Quantity::isValid($count['counted_quantity'])
                    || bccomp($count['counted_quantity'], '0.000', 3) < 0) {
                    $this->em->getConnection()->rollBack();

                    return [['field' => $prefix.'.counted_quantity', 'issue' => 'must be ≥ 0, at most 3 decimal places (REQ-051)']];
                }

                // Snapshot derived stock NOW; delta stays frozen at draft creation.
                $this->guard->lockMaterial($material->getId() ?? 0);
                $derived = InventoryService::normalizeScale($this->guard->resultingBalance($material, '0.000'));
                $counted = Quantity::normalize($count['counted_quantity']);
                $delta = bcsub($counted, $derived, 3);

                $this->em->persist(new StocktakeCount($stocktake, $material, $counted, $derived, $delta));
            }
            $this->em->flush();

            $this->audit->logSuccess(AuditAction::STOCKTAKE_CREATE, 'stocktake', $stocktake->getId() ?? 0, $actor);

            $this->em->getConnection()->commit();

            return $stocktake;
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            throw $e;
        }
    }

    /**
     * @param list<ApprovalInput> $approvals
     *
     * @return Stocktake|list<Error>
     */
    public function commit(int $stocktakeId, array $approvals, ?User $actor = null): Stocktake|array
    {
        $stocktake = $this->stocktakes->find($stocktakeId);
        if (!$stocktake instanceof Stocktake) {
            return [['field' => 'stocktake_id', 'issue' => 'Stocktake not found.']];
        }
        if (StocktakeStatus::DRAFT !== $stocktake->getStatus()) {
            return [['field' => 'stocktake_id', 'issue' => 'STOCKTAKE_NOT_DRAFT: stocktake already committed']];
        }

        $rows = $this->counts->findBy(['stocktake' => $stocktakeId]);
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->getId() ?? 0] = $row;
        }

        $approvalByCountId = [];
        foreach ($approvals as $i => $approval) {
            $countId = (int) $approval['count_id'];
            if (!isset($byId[$countId])) {
                return [['field' => 'approvals.'.($i + 1).'.count_id', 'issue' => 'does not belong to this stocktake']];
            }
            $comment = $approval['approver_comment'] ?? null;
            if (null !== $comment && mb_strlen($comment) > 500) {
                return [['field' => 'approvals.'.($i + 1).'.approver_comment', 'issue' => 'must be at most 500 characters']];
            }
            $approvalByCountId[$countId] = $comment;
        }

        $actor = $actor ?? $this->systemUser->get();
        $committedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->em->getConnection()->beginTransaction();
        try {
            /** @var list<StocktakeCount> $rows */
            foreach ($rows as $count) {
                $material = $count->getMaterial();
                $this->guard->lockMaterial($material->getId() ?? 0);

                $delta = $count->getDelta();
                if (0 === bccomp($delta, '0.000', 3)) {
                    continue; // nothing to reconcile
                }

                if (!isset($approvalByCountId[$count->getId() ?? 0])
                    && $this->isOverTolerance($count)) {
                    $this->em->getConnection()->rollBack();

                    return [['field' => 'approvals', 'issue' => 'APPROVAL_REQUIRED: count #'.($count->getId() ?? 0).' exceeds tolerance (OD-5)']];
                }

                // Zero floor still applies to downward deltas (OD-1).
                if ($this->guard->wouldGoNegative($material, $delta)) {
                    $this->em->getConnection()->rollBack();

                    return [['field' => 'counts', 'issue' => 'NEGATIVE_STOCK_BLOCKED: delta for material '.$material->getName().' would push stock below 0.000 (OD-1)']];
                }

                $movement = new StockMovement(
                    $material,
                    MovementType::STOCKTAKE_DELTA,
                    $delta,
                    $committedAt,
                    SourceDocumentType::STOCKTAKE_COUNT,
                    $count->getId() ?? 0,
                    $actor,
                );
                $this->em->persist($movement);
                $count->attachReconciliationMovement($movement);

                if (isset($approvalByCountId[$count->getId() ?? 0])) {
                    $this->em->persist(new Approval(
                        $actor,
                        'stocktake_count',
                        $count->getId() ?? 0,
                        $approvalByCountId[$count->getId() ?? 0],
                    ));
                }
            }

            $stocktake->markCommitted($actor);
            $this->em->flush();

            $this->audit->logSuccess(AuditAction::STOCKTAKE_COMMIT, 'stocktake', $stocktake->getId() ?? 0, $actor);

            $this->em->getConnection()->commit();

            return $stocktake;
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            throw $e;
        }
    }

    /** OD-5: material threshold when set, else 2% of the snapshot derived stock. */
    private function isOverTolerance(StocktakeCount $count): bool
    {
        $material = $count->getMaterial();
        $threshold = $material->getStocktakeThreshold();
        if (null !== $threshold) {
            $tolerance = $threshold;
        } else {
            $tolerance = bcmul($count->getDerivedStockAtCount(), self::DEFAULT_TOLERANCE_RATE, 3);
        }

        $delta = $count->getDelta();
        $absolute = bccomp($delta, '0.000', 3) < 0 ? substr($delta, 1) : $delta;

        return bccomp($absolute, $tolerance, 3) > 0;
    }
}
