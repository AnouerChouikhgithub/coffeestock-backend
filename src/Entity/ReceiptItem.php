<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReceiptItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One receipt line (domain model). Entirely immutable once recorded (REQ-032).
 * Each item owns exactly one positive RECEIPT stock movement (REQ-031, INV-1).
 */
#[ORM\Entity(repositoryClass: ReceiptItemRepository::class)]
class ReceiptItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Receipt::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'receipt_id', nullable: false)]
    private Receipt $receipt;

    /** Material must be active at creation (service-layer check). */
    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'material_id', nullable: false)]
    private Material $material;

    /** decimal(12,3) > 0 ≤ 10 000 000.000 (REQ-012). */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $quantity;

    /** Nullable; ≥ 0; in the shop's purchasing currency (single-currency assumption). */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $unit_cost = null;

    #[ORM\ManyToOne(targetEntity: Batch::class)]
    #[ORM\JoinColumn(name: 'batch_id', nullable: true)]
    private ?Batch $batch = null;

    /** The one positive RECEIPT movement this item produced (immutable back-reference). */
    #[ORM\ManyToOne(targetEntity: StockMovement::class)]
    #[ORM\JoinColumn(name: 'stock_movement_id', nullable: false)]
    private StockMovement $stock_movement;

    public function __construct(
        Receipt $receipt,
        Material $material,
        string $quantity,
        StockMovement $stockMovement,
        ?string $unitCost = null,
        ?Batch $batch = null,
    ) {
        $this->receipt = $receipt;
        $this->material = $material;
        $this->quantity = $quantity;
        $this->stock_movement = $stockMovement;
        $this->unit_cost = $unitCost;
        $this->batch = $batch;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReceipt(): Receipt
    {
        return $this->receipt;
    }

    public function getMaterial(): Material
    {
        return $this->material;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getUnitCost(): ?string
    {
        return $this->unit_cost;
    }

    public function getBatch(): ?Batch
    {
        return $this->batch;
    }

    public function getStockMovement(): StockMovement
    {
        return $this->stock_movement;
    }
}
