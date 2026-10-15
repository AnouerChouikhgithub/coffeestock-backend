<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Batch;
use App\Entity\Material;
use App\Entity\Receipt;
use App\Entity\ReceiptItem;
use App\Entity\StockMovement;
use App\Entity\Supplier;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\MovementType;
use App\Enum\SourceDocumentType;
use App\Repository\MaterialRepository;
use App\Repository\ReceiptRepository;
use App\Repository\SupplierRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Receipt recording (REQ-030..032). Creates the Receipt, per-item Batches (when
 * supplied or required by OD-6), and exactly one positive RECEIPT movement per item
 * (INV-1, REQ-031) — all in ONE transaction (ADR-0001 consequence: source document
 * and ledger row are atomic). Receipts are immutable once created (REQ-032).
 *
 * @phpstan-type ItemInput array{material_id: int, quantity: string, unit_cost?: string|null, batch?: array{batch_number: string, expires_at?: string|null}|null}
 */
final class ReceiptService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupplierRepository $suppliers,
        private readonly MaterialRepository $materials,
        private readonly SystemUserProvider $systemUser,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param ItemInput[] $items
     *
     * @return Receipt|list<array{field:string, issue:string}>
     */
    public function create(int $supplierId, string $deliveryDate, array $items, ?string $note = null, ?User $actor = null): Receipt|array
    {
        $errors = [];

        $supplier = $this->suppliers->find($supplierId);
        if (!$supplier instanceof Supplier) {
            return [['field' => 'supplier_id', 'issue' => 'Supplier not found.']];
        }
        if (!$supplier->isActive()) {
            return [['field' => 'supplier_id', 'issue' => 'Supplier is inactive (SUPPLIER_INACTIVE).']];
        }

        $delivery = \DateTimeImmutable::createFromFormat('Y-m-d', $deliveryDate, new \DateTimeZone('UTC'));
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        if (false === $delivery) {
            $errors[] = ['field' => 'delivery_date', 'issue' => 'must be a valid YYYY-MM-DD date'];
        } elseif ($delivery > $today) {
            $errors[] = ['field' => 'delivery_date', 'issue' => 'must not be in the future (REQ-030)'];
        }

        if ([] === $items) {
            $errors[] = ['field' => 'items', 'issue' => 'a receipt requires at least one item (REQ-030)'];
        }

        // Validate every item before touching the DB (fail before persist).
        $actor = $actor ?? $this->systemUser->get();
        $resolved = [];
        foreach ($items as $i => $item) {
            $prefix = 'items.'.$i;
            $material = $this->materials->find((int) $item['material_id']);
            if (!$material instanceof Material) {
                $errors[] = ['field' => $prefix.'.material_id', 'issue' => 'Material not found.'];
                continue;
            }
            if (!$material->isActive()) {
                $errors[] = ['field' => $prefix.'.material_id', 'issue' => 'Material is inactive (MATERIAL_INACTIVE).'];
                continue;
            }

            $quantity = (string) $item['quantity'];
            if (!Quantity::isPositiveQuantity($quantity)) {
                $errors[] = ['field' => $prefix.'.quantity', 'issue' => 'must be > 0, at most 3 decimal places, ≤ 10000000.000 (REQ-012)'];
                continue;
            }

            $unitCost = $item['unit_cost'] ?? null;
            if (null !== $unitCost && (!Quantity::isValid((string) $unitCost) || bccomp((string) $unitCost, '0.000', 3) < 0)) {
                $errors[] = ['field' => $prefix.'.unit_cost', 'issue' => 'must be null or a decimal ≥ 0, at most 3 decimal places'];
                continue;
            }

            $batchInput = $item['batch'] ?? null;
            $batchNumber = \is_array($batchInput) ? $batchInput['batch_number'] : null;
            if ($material->isBatchRequired() && (null === $batchNumber || '' === $batchNumber)) {
                $errors[] = ['field' => $prefix.'.batch.batch_number', 'issue' => 'batch_number is required for this material (OD-6)'];
                continue;
            }

            $expiresAt = null;
            if (\is_array($batchInput) && null !== ($batchInput['expires_at'] ?? null)) {
                $expiresAt = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $batchInput['expires_at'], new \DateTimeZone('UTC'));
                if (false === $expiresAt) {
                    $errors[] = ['field' => $prefix.'.batch.expires_at', 'issue' => 'must be a valid YYYY-MM-DD date'];
                    continue;
                }
            }

            $resolved[] = [
                'material' => $material,
                'quantity' => Quantity::normalize($quantity),
                'unit_cost' => null === $unitCost ? null : Quantity::normalize((string) $unitCost),
                'batch_number' => null === $batchNumber || '' === $batchNumber ? null : (string) $batchNumber,
                'expires_at' => $expiresAt,
            ];
        }

        if ([] !== $errors) {
            return $errors;
        }

        // All validation passed — one transaction for document + batches + ledger.
        $this->em->getConnection()->beginTransaction();
        try {
            $receipt = new Receipt($supplier, $delivery, $actor, $note);
            $this->em->persist($receipt);
            $this->em->flush(); // receipt id needed for movement provenance

            /** @var list<array{movement: StockMovement, item: ReceiptItem}> $pairs */
            $pairs = [];
            foreach ($resolved as $r) {
                /** @var Material $material */
                $material = $r['material'];
                $batch = null;
                if (null !== $r['batch_number']) {
                    $batch = $this->findOrCreateBatch($material, $supplier, $r['batch_number'], $delivery, $r['expires_at']);
                }

                // Movement is created before the item because the item holds a real FK
                // back to it; the movement's own provenance pointer (source_document_id)
                // is an unvalidated integer (polymorphic pair, INV-9) and is back-filled
                // in this same transaction once the item id exists — the row is never
                // visible to another transaction before commit, so the ledger stays
                // append-only in effect (no committed row is ever updated).
                $movement = new StockMovement(
                    $material,
                    MovementType::RECEIPT,
                    $r['quantity'],
                    $delivery->setTime(0, 0),
                    SourceDocumentType::RECEIPT_ITEM,
                    0, // back-filled below with the receipt-item id
                    $actor,
                    $batch,
                );
                $this->em->persist($movement);

                $item = new ReceiptItem($receipt, $material, $r['quantity'], $movement, $r['unit_cost'], $batch);
                $this->em->persist($item);
                $receipt->attachItem($item);
                $pairs[] = ['movement' => $movement, 'item' => $item];
            }
            $this->em->flush();

            foreach ($pairs as $pair) {
                $this->em->getConnection()->executeStatement(
                    'UPDATE stock_movement SET source_document_id = :docId WHERE id = :id',
                    ['docId' => $pair['item']->getId(), 'id' => $pair['movement']->getId()],
                );
            }

            $this->em->getConnection()->commit();

            // REQ-110: one audit row per stock-affecting operation, attributed to
            // the acting authenticated user (Phase 9) or the system user fallback.
            $this->audit->logSuccess(AuditAction::RECEIPT_CREATE, 'receipt', $receipt->getId() ?? 0, $actor);

            return $receipt;
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            throw $e;
        }
    }

    private function findOrCreateBatch(Material $material, Supplier $supplier, string $batchNumber, \DateTimeImmutable $receivedAt, ?\DateTimeImmutable $expiresAt): Batch
    {
        $existing = $this->em->getRepository(Batch::class)->findOneBy([
            'material' => $material->getId(),
            'batch_number' => $batchNumber,
        ]);
        if ($existing instanceof Batch) {
            return $existing;
        }

        $batch = new Batch($material, $supplier, $receivedAt, $batchNumber, $expiresAt);
        $this->em->persist($batch);

        return $batch;
    }
}
