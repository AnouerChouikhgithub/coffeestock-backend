<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Server-side opaque bearer session (OD-8, option A). Only the SHA-256 hash of
 * the token is stored, so a DB leak cannot mint working tokens. Expired sessions
 * are rejected at authentication time and removed lazily by the login flow.
 */
#[ORM\Entity(repositoryClass: SessionRepository::class)]
#[ORM\Table(name: 'session')]
#[ORM\Index(name: 'idx_session_expires', columns: ['expires_at'])]
class Session
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private User $user;

    /** hex(SHA-256) of the raw bearer token; unique. */
    #[ORM\Column(length: 64, unique: true)]
    private string $token_hash;

    #[ORM\Column]
    private \DateTimeImmutable $expires_at;

    #[ORM\Column]
    private \DateTimeImmutable $created_at;

    public function __construct(User $user, string $tokenHash, \DateTimeImmutable $expiresAt, ?\DateTimeImmutable $createdAt = null)
    {
        $this->user = $user;
        $this->token_hash = $tokenHash;
        $this->expires_at = $expiresAt;
        $this->created_at = $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->token_hash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expires_at;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expires_at;
    }
}
