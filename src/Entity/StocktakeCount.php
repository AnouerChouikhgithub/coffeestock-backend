<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StocktakeCountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One material's physical counted quantity within a Stocktake (domain model).
 * Editable while DRAFT; frozen at COMMIT. derived_stock_at_count is snapshotted at
 * draft creation; delta = counted_quantity − derived_stock_at_count (always maintained,
 * domain model).
 */
#[ORM\Entity(repositoryClass: StocktakeCountRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_stocktake_count_material', columns: ['stocktake_id', 'material_id'])]
class StocktakeCount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Stocktake::class)]
    #[ORM\JoinColumn(name: 'stocktake_id', nullable: false)]
    private Stocktake $stocktake;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'material_id', nullable: false)]
    private Material $material;

    /** decimal(12,3) ≥ 0 ≤ 10 000 000.000. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $counted_quantity;

    /** Ledger-derived stock snapshotted at DRAFT creation. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $derived_stock_at_count;

    /** decimal(13,3) = counted_quantity − derived_stock_at_count. */
    #[ORM\Column(type: Types::DECIMAL, precision: 13, scale: 3)]
    private string $delta;

    /** Set when the stocktake commits (STOCKTAKE_DELTA movement). */
    #[ORM\ManyToOne(targetEntity: StockMovement::class)]
    #[ORM\JoinColumn(name: 'reconciliation_movement_id', nullable: true)]
    private ?StockMovement $reconciliation_movement = null;

    public function __construct(
        Stocktake $stocktake,
        Material $material,
        string $countedQuantity,
        string $derivedStockAtCount,
        string $delta,
    ) {
        $this->stocktake = $stocktake;
        $this->material = $material;
        $this->counted_quantity = $countedQuantity;
        $this->derived_stock_at_count = $derivedStockAtCount;
        $this->delta = $delta;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStocktake(): Stocktake
    {
        return $this->stocktake;
    }

    public function getMaterial(): Material
    {
        return $this->material;
    }

    public function getCountedQuantity(): string
    {
        return $this->counted_quantity;
    }

    public function getDerivedStockAtCount(): string
    {
        return $this->derived_stock_at_count;
    }

    public function getDelta(): string
    {
        return $this->delta;
    }

    public function getReconciliationMovement(): ?StockMovement
    {
        return $this->reconciliation_movement;
    }
}
