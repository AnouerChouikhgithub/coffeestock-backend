<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_username', columns: ['username'])]
class User implements \Symfony\Component\Security\Core\User\UserInterface, \Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $username;

    /**
     * Password credential storage comes in Phase 9. Phase 2 stores a placeholder hash
     * so the column and NOT NULL constraint exist from day one.
     */
    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(enumType: UserRole::class)]
    private UserRole $role = UserRole::OPERATOR;

    #[ORM\Column]
    private bool $is_active = true;

    #[ORM\Column]
    private \DateTimeImmutable $created_at;

    public function __construct(string $username, UserRole $role)
    {
        $this->username = $username;
        $this->role = $role;
        $this->created_at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return '' === $this->password ? null : $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): void
    {
        $this->role = $role;
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

    // --- UserInterface (Phase 9 security plumbing) ---------------------------

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_'.$this->role->value];
    }

    /**
     * Symfony 7.3 deprecated implementing this method (use __serialize() instead).
     * The attribute is the documented opt-in for an intentionally empty
     * implementation, and keeps `failOnDeprecation` runs green. No plain-text
     * credential is ever stored on this entity, so there is nothing to erase.
     */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
