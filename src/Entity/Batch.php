<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Traceability grouping for received quantities of one material (domain model).
 * batch_number is nullable; the OD-6 batch_required policy on the material is enforced
 * by the service layer at receipt creation, not at the DB level.
 */
#[ORM\Entity(repositoryClass: BatchRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_batch_material_number', columns: ['material_id', 'batch_number'])]
class Batch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'material_id', nullable: false)]
    private Material $material;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: false)]
    private Supplier $supplier;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $batch_number = null;

    /** UTC; set from the Receipt's delivery date (domain model). */
    #[ORM\Column]
    private \DateTimeImmutable $received_at;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expires_at = null;

    public function __construct(
        Material $material,
        Supplier $supplier,
        \DateTimeImmutable $receivedAt,
        ?string $batchNumber = null,
        ?\DateTimeImmutable $expiresAt = null,
    ) {
        $this->material = $material;
        $this->supplier = $supplier;
        $this->received_at = $receivedAt;
        $this->batch_number = $batchNumber;
        $this->expires_at = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMaterial(): Material
    {
        return $this->material;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getBatchNumber(): ?string
    {
        return $this->batch_number;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->received_at;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expires_at;
    }
}
