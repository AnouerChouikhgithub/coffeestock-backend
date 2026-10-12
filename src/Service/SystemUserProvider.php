<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ledger and audit rows require non-null attribution (INV-11). Until Phase 9 ships
 * real authentication (OD-8 cookie sessions), stock-affecting API writes attribute
 * to this find-or-creates "system" user. Phase 9 replaces this with the authenticated
 * principal — see docs/plans/phase-4-plan.md.
 */
final class SystemUserProvider
{
    private const USERNAME = 'system';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function get(): User
    {
        $user = $this->users->findByUsername(self::USERNAME);
        if (!$user instanceof User) {
            $user = new User(self::USERNAME, UserRole::OPERATOR);
            $user->setPassword('');
            $this->em->persist($user);
            $this->em->flush();
        }

        return $user;
    }
}
