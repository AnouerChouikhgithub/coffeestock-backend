<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuditAction;
use App\Enum\AuditOutcome;
use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only record of who performed which stock-affecting operation (REQ-110).
 * Distinct from the ledger: ledger records stock truth; audit records accountability.
 * Retention/access per OD-4 (indefinite, Admin-read); no purge code exists (REQ-111).
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Index(name: 'idx_audit_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_action', columns: ['action'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_user_id', nullable: false)]
    private User $actor_user;

    #[ORM\Column(enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(length: 64)]
    private string $target_entity;

    #[ORM\Column]
    private int $target_entity_id;

    #[ORM\Column]
    private \DateTimeImmutable $occurred_at;

    #[ORM\Column(enumType: AuditOutcome::class)]
    private AuditOutcome $outcome;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $detail = null;

    public function __construct(
        User $actorUser,
        AuditAction $action,
        string $targetEntity,
        int $targetEntityId,
        AuditOutcome $outcome,
        ?array $detail = null,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->actor_user = $actorUser;
        $this->action = $action;
        $this->target_entity = $targetEntity;
        $this->target_entity_id = $targetEntityId;
        $this->outcome = $outcome;
        $this->detail = $detail;
        $this->occurred_at = $occurredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActorUser(): User
    {
        return $this->actor_user;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getTargetEntity(): string
    {
        return $this->target_entity;
    }

    public function getTargetEntityId(): int
    {
        return $this->target_entity_id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurred_at;
    }

    public function getOutcome(): AuditOutcome
    {
        return $this->outcome;
    }

    /** @return array<string, mixed>|null */
    public function getDetail(): ?array
    {
        return $this->detail;
    }
}
