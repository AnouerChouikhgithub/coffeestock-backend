<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Session;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Session>
 */
class SessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Session::class);
    }

    /** Cached user lookup by token hash. Returns null when unknown/expired (checked by caller). */
    public function findByTokenHash(string $tokenHash): ?Session
    {
        return $this->createQueryBuilder('s')
            ->where('s.token_hash = :hash')
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Lazily delete token sessions whose expiry has passed (housekeeping on login). */
    public function deleteExpired(): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.expires_at <= :now')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->execute();
    }
}
