<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Supplier;
use App\Repository\SupplierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Supplier catalogue rules (REQ-020 unique name, REQ-021 soft deactivation).
 */
final class SupplierService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupplierRepository $suppliers,
    ) {
    }

    /**
     * @return Supplier|list<array{field:string, issue:string}>
     */
    public function create(string $name, ?string $contactName, ?string $email, ?string $phone): Supplier|array
    {
        if ('' === $name || mb_strlen($name) > 160) {
            return [['field' => 'name', 'issue' => 'must be 1..160 characters']];
        }
        if (null !== $contactName && mb_strlen($contactName) > 120) {
            return [['field' => 'contact_name', 'issue' => 'must be at most 120 characters']];
        }
        if (null !== $email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [['field' => 'email', 'issue' => 'must be a valid email address']];
        }
        if (null !== $phone && mb_strlen($phone) > 32) {
            return [['field' => 'phone', 'issue' => 'must be at most 32 characters']];
        }
        if (null !== $this->suppliers->findByName($name)) {
            return [['field' => 'name', 'issue' => 'A supplier with this name already exists.']];
        }

        $supplier = new Supplier($name);
        $supplier->setContactName($contactName);
        $supplier->setEmail($email);
        $supplier->setPhone($phone);

        $this->em->persist($supplier);
        $this->em->flush();

        return $supplier;
    }

    /**
     * @return Supplier|list<array{field:string, issue:string}>
     */
    public function update(Supplier $supplier, ?string $name, ?string $contactName, ?string $email, ?string $phone): Supplier|array
    {
        if (null !== $name) {
            if ('' === $name || mb_strlen($name) > 160) {
                return [['field' => 'name', 'issue' => 'must be 1..160 characters']];
            }
            $dup = $this->suppliers->findByName($name);
            if (null !== $dup && $dup->getId() !== $supplier->getId()) {
                return [['field' => 'name', 'issue' => 'A supplier with this name already exists.']];
            }
            $supplier->setName($name);
        }
        if (null !== $contactName) {
            if (mb_strlen($contactName) > 120) {
                return [['field' => 'contact_name', 'issue' => 'must be at most 120 characters']];
            }
            $supplier->setContactName($contactName);
        }
        if (null !== $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [['field' => 'email', 'issue' => 'must be a valid email address']];
            }
            $supplier->setEmail($email);
        }
        if (null !== $phone) {
            if (mb_strlen($phone) > 32) {
                return [['field' => 'phone', 'issue' => 'must be at most 32 characters']];
            }
            $supplier->setPhone($phone);
        }

        $this->em->flush();

        return $supplier;
    }

    /**
     * @return array{items: list<Supplier>, total: int}
     */
    public function list(int $page, int $perPage, bool $includeInactive, ?string $search): array
    {
        $qb = $this->suppliers->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC');

        if (!$includeInactive) {
            $qb->andWhere('s.is_active = true');
        }
        if (null !== $search && '' !== $search) {
            $qb->andWhere('LOWER(s.name) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
        $paginator = new Paginator($qb, true);

        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => \count($paginator),
        ];
    }

    /** Soft deactivation (REQ-021): never deletes, keeps history attributable. */
    public function deactivate(Supplier $supplier): void
    {
        $supplier->setIsActive(false);
        $this->em->flush();
    }
}
