<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StocktakeStatus;
use App\Repository\StocktakeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A recorded physical count of one or more materials at a point in time (REQ-051).
 * Created DRAFT; becomes COMMITTED wholesale (never partially) — mutable only along
 * that one transition (domain model). Deleting a DRAFT is allowed per OD-7 default;
 * COMMITTED stocktakes are immutable.
 */
#[ORM\Entity(repositoryClass: StocktakeRepository::class)]
class Stocktake
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $taken_at;

    #[ORM\Column(enumType: StocktakeStatus::class)]
    private StocktakeStatus $status = StocktakeStatus::DRAFT;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', nullable: false)]
    private User $created_by_user;

    /** Set at commit; must satisfy the OD-5 approver role (enforced by service layer). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'committed_by_user_id', nullable: true)]
    private ?User $committed_by_user = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $committed_at = null;

    public function __construct(\DateTimeImmutable $takenAt, User $createdByUser)
    {
        $this->taken_at = $takenAt;
        $this->created_by_user = $createdByUser;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTakenAt(): \DateTimeImmutable
    {
        return $this->taken_at;
    }

    public function getStatus(): StocktakeStatus
    {
        return $this->status;
    }

    public function getCreatedByUser(): User
    {
        return $this->created_by_user;
    }

    public function getCommittedByUser(): ?User
    {
        return $this->committed_by_user;
    }

    public function getCommittedAt(): ?\DateTimeImmutable
    {
        return $this->committed_at;
    }

    /** Internal-only transition; the service layer guards role/DC rules. */
    public function markCommitted(User $committedByUser): void
    {
        if (StocktakeStatus::DRAFT !== $this->status) {
            throw new \LogicException('Only a DRAFT stocktake can be committed.');
        }

        $this->status = StocktakeStatus::COMMITTED;
        $this->committed_by_user = $committedByUser;
        $this->committed_at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
