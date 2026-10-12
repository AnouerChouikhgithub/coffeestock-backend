<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MovementType;
use App\Enum\SourceDocumentType;
use App\Repository\StockMovementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The append-only ledger (ADR-0001). The application must never UPDATE or DELETE a
 * StockMovement row. Corrections happen only as new compensating movements (INV-2, REQ-060).
 *
 * Provenance is polymorphic: source_document_type + source_document_id resolve to the
 * source document (INV-9). No FK is placed on the pair because the source documents ship
 * across phases; each source document holds a real FK back to its movement instead.
 */
#[ORM\Entity(repositoryClass: StockMovementRepository::class)]
#[ORM\Index(name: 'idx_movement_material_occurred', columns: ['material_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_movement_material_type', columns: ['material_id', 'movement_type'])]
class StockMovement
{
    public const QUANTITY_SCALE = 3;
    public const SIGNED_QUANTITY_PRECISION = 13;
    public const SIGNED_QUANTITY_MAX = '10000000.000';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'material_id', nullable: false)]
    private Material $material;

    #[ORM\Column(enumType: MovementType::class)]
    private MovementType $movement_type;

    /** Sign rules per INV-4: RECEIPT >0; CONSUMPTION/WASTE <0; ADJUSTMENT/STOCKTAKE_DELTA either. */
    #[ORM\Column(type: Types::DECIMAL, precision: 13, scale: 3)]
    private string $signed_quantity;

    #[ORM\Column]
    private \DateTimeImmutable $occurred_at;

    #[ORM\Column(enumType: SourceDocumentType::class)]
    private SourceDocumentType $source_document_type;

    #[ORM\Column(type: Types::INTEGER)]
    private int $source_document_id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false)]
    private User $created_by_user;

    #[ORM\ManyToOne(targetEntity: Batch::class)]
    #[ORM\JoinColumn(name: 'batch_id', nullable: true)]
    private ?Batch $batch = null;

    public function __construct(
        Material $material,
        MovementType $movementType,
        string $signedQuantity,
        \DateTimeImmutable $occurredAt,
        SourceDocumentType $sourceDocumentType,
        int $sourceDocumentId,
        User $createdByUser,
        ?Batch $batch = null,
    ) {
        $this->material = $material;
        $this->movement_type = $movementType;
        $this->signed_quantity = $signedQuantity;
        $this->occurred_at = $occurredAt;
        $this->source_document_type = $sourceDocumentType;
        $this->source_document_id = $sourceDocumentId;
        $this->created_by_user = $createdByUser;
        $this->batch = $batch;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMaterial(): Material
    {
        return $this->material;
    }

    public function getMovementType(): MovementType
    {
        return $this->movement_type;
    }

    /** Exact scale-3 string, never a float (REQ-010, api-contract decimal transport rule). */
    public function getSignedQuantity(): string
    {
        return $this->signed_quantity;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurred_at;
    }

    public function getSourceDocumentType(): SourceDocumentType
    {
        return $this->source_document_type;
    }

    public function getSourceDocumentId(): int
    {
        return $this->source_document_id;
    }

    public function getCreatedByUser(): User
    {
        return $this->created_by_user;
    }

    public function getBatch(): ?Batch
    {
        return $this->batch;
    }
}
