<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Approval;
use App\Entity\Material;
use App\Entity\StockAdjustment;
use App\Entity\StockMovement;
use App\Enum\AuditAction;
use App\Enum\MovementType;
use App\Enum\SourceDocumentType;
use App\Repository\MaterialRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Signed stock adjustments (REQ-050/BR-17): mandated reason, unconditional +/-
 * quantity (scale 3), zero-floor enforcement via StockGuard unless the request
 * explicitly flags approval per OD-1, and OD-5 threshold approval when the
 * material's stocktake_threshold is exceeded by |signed_delta|.
 *
 * Approval is modelled as state (an Approval row) and enforced as a role check
 * in Phase 9; until then the acting "system" user is accepted as approver.
 *
 * @phpstan-type Error array{field: string, issue: string}
 */
final class AdjustmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialRepository $materials,
        private readonly StockGuard $guard,
        private readonly SystemUserProvider $systemUser,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{adjustment: StockAdjustment, movement: StockMovement, approval: Approval|null}|Error
     */
    public function apply(
        int $materialId,
        string $signedDelta,
        string $reason,
        ?string $recordedAt,
        bool $approved,
    ): array {
        $material = $this->materials->find($materialId);
        if (!$material instanceof Material) {
            return ['field' => 'material_id', 'issue' => 'Material not found.'];
        }
        if (!$material->isActive()) {
            return ['field' => 'material_id', 'issue' => 'Material is inactive (MATERIAL_INACTIVE).'];
        }
        if (!Quantity::isNonZero($signedDelta)) {
            return ['field' => 'signed_delta', 'issue' => 'must be non-zero, at most 3 decimal places, within ±10000000.000 (REQ-050)'];
        }
        if ('' === trim($reason)) {
            return ['field' => 'reason', 'issue' => 'must not be empty (REQ-050/BR-17)'];
        }
        if (mb_strlen($reason) > 500) {
            return ['field' => 'reason', 'issue' => 'must be at most 500 characters'];
        }

        $parsedAt = Timestamp::parseUtc($recordedAt, 'recorded_at');
        if (\is_array($parsedAt)) {
            return $parsedAt;
        }

        $signed = Quantity::normalize($signedDelta);
        $actor = $this->systemUser->get();

        $this->em->getConnection()->beginTransaction();
        try {
            $this->guard->lockMaterial($material->getId() ?? 0);

            // OD-5: |delta| beyond the material's stocktake threshold needs approval.
            $threshold = $material->getStocktakeThreshold();
            $absolute = bccomp($signed, '0.000', 3) < 0 ? substr($signed, 1) : $signed;
            $needsThresholdApproval = null !== $threshold && bccomp($absolute, $threshold, 3) > 0;
            if ($needsThresholdApproval && !$approved) {
                $this->em->getConnection()->rollBack();

                return ['field' => 'signed_delta', 'issue' => 'APPROVAL_REQUIRED: |signed_delta| exceeds the material stocktake threshold (OD-5)'];
            }

            // OD-1: zero floor unless the request is explicitly approved.
            if ($this->guard->wouldGoNegative($material, $signed) && !$approved) {
                $this->em->getConnection()->rollBack();

                return ['field' => 'signed_delta', 'issue' => 'NEGATIVE_STOCK_BLOCKED: entry would push stock below 0.000 (OD-1, resubmit with approved=true)'];
            }

            $movement = new StockMovement(
                $material,
                MovementType::ADJUSTMENT,
                $signed,
                $parsedAt,
                SourceDocumentType::ADJUSTMENT,
                0, // back-filled below
                $actor,
            );
            $this->em->persist($movement);

            $adjustment = new StockAdjustment($material, $signed, $reason, $movement, $actor);
            $this->em->persist($adjustment);
            $this->em->flush();

            $this->em->getConnection()->executeStatement(
                'UPDATE stock_movement SET source_document_id = :docId WHERE id = :id',
                ['docId' => $adjustment->getId(), 'id' => $movement->getId()],
            );

            $approval = null;
            if ($approved) {
                $approval = new Approval($actor, 'stock_adjustment', $adjustment->getId() ?? 0, 'approved via API flag (OD-1/OD-5)');
                $this->em->persist($approval);
                $this->em->flush();
            }

            $this->audit->logSuccess(AuditAction::ADJUSTMENT_CREATE, 'stock_adjustment', $adjustment->getId() ?? 0, $actor);

            $this->em->getConnection()->commit();

            return ['adjustment' => $adjustment, 'movement' => $movement, 'approval' => $approval];
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            throw $e;
        }
    }
}
