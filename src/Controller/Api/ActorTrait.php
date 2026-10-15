<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;

/**
 * Phase 9 attribution: API writes audit/ledger under the authenticated principal.
 * The service layer still falls back to the system user for fixtures/CLI paths
 * (distinguishable "system" actor, pre-approved Phase 4 behaviour).
 */
trait ActorTrait
{
    private function userOrNull(): ?User
    {
        /** @var User|null $user */
        $user = $this->getUser();

        return $user instanceof User && null !== $user->getId() ? $user : null;
    }
}
