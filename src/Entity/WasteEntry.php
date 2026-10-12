<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WasteEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Waste: a stock decrease for loss/spoilage/disposal, distinct from consumption at
 * ledger level (REQ-041). Same shape as ConsumptionEntry; the ledger movement's type
 * (WASTE) is what distinguishes it.
 */
#[ORM\Entity(repositoryClass: WasteEntryRepository::class)]
class WasteEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'material_id', nullable: false)]
    private Material $material;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $quantity;

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
