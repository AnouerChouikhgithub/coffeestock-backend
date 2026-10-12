<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SupplierRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SupplierRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_supplier_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class Supplier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $contact_name = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $phone = null;

    #[ORM\Column]
    private bool $is_active = true;

    #[ORM\Column]
    private \DateTimeImmutable $created_at;

    #[ORM\Column]
    private \DateTimeImmutable $updated_at;

    public function __construct(string $name)
    {
        $this->name = $name;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->created_at = $now;
        $this->updated_at = $now;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updated_at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getContactName(): ?string
    {
        return $this->contact_name;
    }

    public function setContactName(?string $contactName): void
    {
        $this->contact_name = $contactName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updated_at;
    }
}
