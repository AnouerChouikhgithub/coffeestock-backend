<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApprovalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Approval record for policies that require second-person sign-off (OD-3 compensating
 * corrections, OD-5 over-tolerance variances). Append-only.
 */
#[ORM\Entity(repositoryClass: ApprovalRepository::class)]
class Approval
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approver_user_id', nullable: false)]
    private User $approver_user;

    #[ORM\Column(length: 64)]
    private string $target_entity;

    #[ORM\Column]
    private int $target_entity_id;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private \DateTimeImmutable $created_at;

    public function __construct(User $approverUser, string $targetEntity, int $targetEntityId, ?string $comment = null)
    {
        $this->approver_user = $approverUser;
        $this->target_entity = $targetEntity;
        $this->target_entity_id = $targetEntityId;
        $this->comment = $comment;
        $this->created_at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApproverUser(): User
    {
        return $this->approver_user;
    }

    public function getTargetEntity(): string
    {
        return $this->target_entity;
    }

    public function getTargetEntityId(): int
    {
        return $this->target_entity_id;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->created_at;
    }
}
