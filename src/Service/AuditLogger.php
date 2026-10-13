<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\AuditOutcome;
use Doctrine\ORM\EntityManagerInterface;

/**
 * REQ-110: one append-only audit row per stock-affecting operation. Retention
 * indefinite until OD-4 decides otherwise (REQ-111); no purge exists.
 */
final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed>|null $detail
     */
    public function logSuccess(AuditAction $action, string $targetEntity, int $targetEntityId, User $actor, ?array $detail = null): void
    {
        $this->em->persist(new AuditLog($actor, $action, $targetEntity, $targetEntityId, AuditOutcome::SUCCESS, $detail));
        $this->em->flush();
    }
}
