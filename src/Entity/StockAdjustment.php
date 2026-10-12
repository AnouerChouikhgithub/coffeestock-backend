<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StockAdjustmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Signed, operator-initiated correction movement that is not a receipt, consumption,
 * waste, or stocktake delta (REQ-050). Reason is mandatory (BR-17). Entirely immutable.
 */
#[ORM\Entity(repositoryClass: StockAdjustmentRepository::class)]
class StockAdjustment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'material_id', nullable: false)]
    private Material $material;

    /** decimal(13,3) ≠ 0, within −10 000 000.000 … +10 000 000.000. */
    #[ORM\Column(type: Types::DECIMAL, precision: 13, scale: 3)]
    private string $signed_delta;

    #[ORM\Column(length: 500)]
    private string $reason;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false)]
    private User $created_by_user;

    /** Populated when OD-1/OD-5 policies require pre-approval. */
    #[ORM\ManyToOne(targetEntity: Approval::class)]
    #[ORM\JoinColumn(name: 'approval_id', nullable: true)]
    private ?Approval $approval = null;

    #[ORM\ManyToOne(targetEntity: StockMovement::class)]
    #[ORM\JoinColumn(name: 'stock_movement_id', nullable: false)]
    private StockMovement $stock_movement;

    public function __construct(
        Material $material,
        string $signedDelta,
        string $reason,
        StockMovement $stockMovement,
        User $createdByUser,
        ?Approval $approval = null,
    ) {
        $this->material = $material;
        $this->signed_delta = $signedDelta;
        $this->reason = $reason;
        $this->stock_movement = $stockMovement;
        $this->created_by_user = $createdByUser;
        $this->approval = $approval;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMaterial(): Material
    {
        return $this->material;
    }

    public function getSignedDelta(): string
    {
        return $this->signed_delta;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedByUser(): User
    {
        return $this->created_by_user;
    }

    public function getApproval(): ?Approval
    {
        return $this->approval;
    }

    public function getStockMovement(): StockMovement
    {
        return $this->stock_movement;
    }
}
