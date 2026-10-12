<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConsumptionEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Consumption: a recorded stock decrease for operational/production use (REQ-040).
 * Entirely immutable once recorded (domain model); the ledger movement carries the
 * negative sign (INV-4).
 */
#[ORM\Entity(repositoryClass: ConsumptionEntryRepository::class)]
class ConsumptionEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'material_id', nullable: false)]
    private Material $material;

    /** decimal(12,3) > 0 ≤ 10 000 000.000; interpreted as a decrease (REQ-012). */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $quantity;

    /** UTC; ≤ now; back-dating per OD-3 (REQ-042). */
    #[ORM\Column]
    private \DateTimeImmutable $recorded_at;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false)]
    private User $created_by_user;

    #[ORM\ManyToOne(targetEntity: StockMovement::class)]
    #[ORM\JoinColumn(name: 'stock_movement_id', nullable: false)]
    private StockMovement $stock_movement;

    public function __construct(
        Material $material,
        string $quantity,
        \DateTimeImmutable $recordedAt,
        StockMovement $stockMovement,
        User $createdByUser,
        ?string $note = null,
    ) {
        $this->material = $material;
        $this->quantity = $quantity;
        $this->recorded_at = $recordedAt;
        $this->stock_movement = $stockMovement;
        $this->created_by_user = $createdByUser;
        $this->note = $note;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMaterial(): Material
    {
        return $this->material;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recorded_at;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedByUser(): User
    {
        return $this->created_by_user;
    }

    public function getStockMovement(): StockMovement
    {
        return $this->stock_movement;
    }
}
