<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BaseUnit;
use App\Enum\MaterialType;
use App\Repository\MaterialRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MaterialRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_material_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class Material
{
    public const QUANTITY_SCALE = 3;
    public const QUANTITY_MAX = '10000000.000';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(enumType: MaterialType::class)]
    private MaterialType $type = MaterialType::RAW;

    /** Immutable once any Stock Movement exists (domain model immutability rule). */
    #[ORM\Column(enumType: BaseUnit::class)]
    private BaseUnit $base_unit = BaseUnit::PIECE;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $reorder_threshold = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $stocktake_threshold = null;

    /** OD-6 default: ON for RAW materials, OFF for consumables (set by the service layer). */
    #[ORM\Column]
    private bool $batch_required = false;

    #[ORM\Column]
    private bool $is_active = true;

    #[ORM\Column]
    private \DateTimeImmutable $created_at;

    #[ORM\Column]
    private \DateTimeImmutable $updated_at;

    public function __construct(string $name, MaterialType $type, BaseUnit $baseUnit)
    {
        $this->name = $name;
        $this->type = $type;
        $this->base_unit = $baseUnit;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->created_at = $now;
        $this->updated_at = $now;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updated_at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getType(): MaterialType
    {
        return $this->type;
    }

    public function setType(MaterialType $type): void
    {
        $this->type = $type;
    }

    public function getBaseUnit(): BaseUnit
    {
        return $this->base_unit;
    }

    public function getReorderThreshold(): ?string
    {
        return $this->reorder_threshold;
    }

    public function setReorderThreshold(?string $reorderThreshold): void
    {
        $this->reorder_threshold = $reorderThreshold;
    }

    public function getStocktakeThreshold(): ?string
    {
        return $this->stocktake_threshold;
    }

    public function setStocktakeThreshold(?string $stocktakeThreshold): void
    {
        $this->stocktake_threshold = $stocktakeThreshold;
    }

    public function isBatchRequired(): bool
    {
        return $this->batch_required;
    }

    public function setBatchRequired(bool $batchRequired): void
    {
        $this->batch_required = $batchRequired;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->is_active = $isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->created_at;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updated_at;
    }
}
