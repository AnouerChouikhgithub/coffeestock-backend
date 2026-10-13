<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReceiptRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Receipt header (domain model). Entirely immutable once recorded (REQ-032);
 * corrections happen only as compensating ledger movements per OD-3.
 */
#[ORM\Entity(repositoryClass: ReceiptRepository::class)]
#[ORM\Table(name: 'receipt')]
class Receipt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Must reference an active supplier at creation (enforced by the service layer). */
    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: false)]
    private Supplier $supplier;

    /** Delivery date ≤ today (UTC), defaults to today (REQ-030). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $delivery_date;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false)]
    private User $created_by_user;

    #[ORM\Column]
    private \DateTimeImmutable $created_at;

    /**
     * Mapped inverse side of ReceiptItem#receipt: hydration in a fresh kernel must
     * lazy-load the items (an unmapped PHP array would silently read as empty).
     *
     * @var Collection<int, ReceiptItem>
     */
    #[ORM\OneToMany(targetEntity: ReceiptItem::class, mappedBy: 'receipt')]
    private Collection $items;

    /**
     * @param list<ReceiptItem> $items
     */
    public function __construct(Supplier $supplier, \DateTimeImmutable $deliveryDate, User $createdByUser, ?string $note = null, array $items = [])
    {
        $this->supplier = $supplier;
        $this->delivery_date = $deliveryDate;
        $this->created_by_user = $createdByUser;
        $this->note = $note;
        $this->created_at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->items = new ArrayCollection($items);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getDeliveryDate(): \DateTimeImmutable
    {
        return $this->delivery_date;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedByUser(): User
    {
        return $this->created_by_user;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->created_at;
    }

    /** @return list<ReceiptItem> */
    public function getItems(): array
    {
        return $this->items->toArray();
    }

    /**
     * Used only while composing the receipt inside its creation transaction — the
     * header and its items are immutable once that transaction commits (REQ-032).
     */
    public function attachItem(ReceiptItem $item): void
    {
        $this->items->add($item);
    }
}
