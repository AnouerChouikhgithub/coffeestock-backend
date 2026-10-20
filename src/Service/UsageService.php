<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ConsumptionEntry;
use App\Entity\Material;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Entity\WasteEntry;
use App\Enum\AuditAction;
use App\Enum\MovementType;
use App\Enum\SourceDocumentType;
use App\Repository\MaterialRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Consumption and waste recording (REQ-040..042). Each entry creates exactly one
 * negative ledger movement of its type (INV-4) in the same transaction as the
 * source document. Consumption and waste are identical in shape but distinct at
 * ledger level (BR-14/BR-15).
 *
 * @phpstan-type Error array{field: string, issue: string}
 */
final class UsageService
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
     * @return array{entry: ConsumptionEntry|WasteEntry, movement: StockMovement}|Error
     */
    public function record(int $materialId, string $quantity, ?string $recordedAt, ?string $note, bool $isWaste, ?User $actor = null): array
    {
        $material = $this->materials->find($materialId);
        if (!$material instanceof Material) {
            return ['field' => 'material_id', 'issue' => 'Material not found.'];
        }
        if (!$material->isActive()) {
            return ['field' => 'material_id', 'issue' => 'Material is inactive (MATERIAL_INACTIVE).'];
        }
        if (!Quantity::isPositiveQuantity($quantity)) {
            return ['field' => 'quantity', 'issue' => 'must be > 0, at most 3 decimal places, ≤ 10000000.000 (REQ-012)'];
        }
        if ($isWaste && (null === $note || '' === trim($note))) {
            // BR: waste requires a reason/category; note carries it (no schema change needed).
            return ['field' => 'note', 'issue' => 'a reason is required for waste (BR-15)'];
        }
        if (null !== $note && mb_strlen($note) > 500) {
            return ['field' => 'note', 'issue' => 'must be at most 500 characters'];
        }

        $parsedAt = Timestamp::parseUtc($recordedAt, 'recorded_at');
        if (\is_array($parsedAt)) {
            return $parsedAt;
        }

        $signed = '-'.Quantity::normalize($quantity);
        $actor = $actor ?? $this->systemUser->get();
        $movementType = $isWaste ? MovementType::WASTE : MovementType::CONSUMPTION;
        $docType = $isWaste ? SourceDocumentType::WASTE_ENTRY : SourceDocumentType::CONSUMPTION_ENTRY;

        $this->em->getConnection()->beginTransaction();
        try {
            // Serialize with concurrent writers on this material before reading the balance (OD-1).
            $this->guard->lockMaterial($material->getId() ?? 0);
            if ($this->guard->wouldGoNegative($material, $signed)) {
                $this->em->getConnection()->rollBack();

                return ['field' => 'quantity', 'issue' => 'NEGATIVE_STOCK_BLOCKED: entry would push stock below 0.000 (OD-1)'];
            }

            $movement = new StockMovement(
                $material,
                $movementType,
                $signed,
                $parsedAt,
                $docType,
                0, // back-filled with the source-document id below
                $actor,
            );
            $this->em->persist($movement);

            if ($isWaste) {
                $entry = new WasteEntry($material, Quantity::normalize($quantity), $parsedAt, $movement, $actor, $note);
            } else {
                $entry = new ConsumptionEntry($material, Quantity::normalize($quantity), $parsedAt, $movement, $actor, $note);
            }
            $this->em->persist($entry);
            $this->em->flush();

            // Intra-transaction provenance back-fill (INV-9), same pattern as receipts:
            // the movement row is never visible to another transaction before commit.
            $this->em->getConnection()->executeStatement(
                'UPDATE stock_movement SET source_document_id = :docId WHERE id = :id',
                ['docId' => $entry->getId(), 'id' => $movement->getId()],
            );

            $this->audit->logSuccess(
                $isWaste ? AuditAction::WASTE_CREATE : AuditAction::CONSUMPTION_CREATE,
                $isWaste ? 'waste_entry' : 'consumption_entry',
                $entry->getId() ?? 0,
                $actor,
            );

            $this->em->getConnection()->commit();

            return ['entry' => $entry, 'movement' => $movement];
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            throw $e;
        }
    }
}
