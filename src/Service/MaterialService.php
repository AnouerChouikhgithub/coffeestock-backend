<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Material\CreateMaterialInput;
use App\Dto\Material\UpdateMaterialInput;
use App\Entity\Material;
use App\Enum\BaseUnit;
use App\Enum\MaterialType;
use App\Repository\MaterialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Material catalogue business rules (REQ-001..004, BR-1, BR-2, BR-4).
 * Messenger/transaction-only at app level; uniqueness is DB-guaranteed and mapped here
 * to the contract's DUPLICATE_NAME code.
 */
final class MaterialService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialRepository $materials,
    ) {
    }

    /**
     * @return Material|list<array{field:string, issue:string}>
     */
    public function create(CreateMaterialInput $input): Material|array
    {
        $errors = [];

        if ('' === $input->name || mb_strlen($input->name) > 120) {
            $errors[] = ['field' => 'name', 'issue' => 'must be 1..120 characters'];
        }
        if (null === MaterialType::tryFrom($input->type)) {
            $errors[] = ['field' => 'type', 'issue' => 'must be RAW or CONSUMABLE'];
        }
        $baseUnit = BaseUnit::tryFrom($input->base_unit);
        if (null === $baseUnit) {
            $errors[] = ['field' => 'base_unit', 'issue' => 'must be one of g, kg, ml, l, piece'];
        }
        foreach (['reorder_threshold' => $input->reorder_threshold, 'stocktake_threshold' => $input->stocktake_threshold] as $f => $v) {
            if (null !== $v && !self::isValidQuantity($v)) {
                $errors[] = ['field' => $f, 'issue' => 'must be a decimal ≥ 0, at most 3 decimal places, ≤ 10000000.000'];
            }
        }
        if (null !== $baseUnit && MaterialType::RAW === MaterialType::tryFrom($input->type) && '' !== $input->name
            && null === $this->findDuplicate($input->name)) {
            // batch_required default per OD-6: RAW gets true (DB persist below)
        }
        if ([] !== $errors) {
            return $errors;
        }

        if (null !== $this->findDuplicate($input->name)) {
            return [['field' => 'name', 'issue' => 'A material with this name already exists.']];
        }

        $material = new Material($input->name, MaterialType::from($input->type), $baseUnit ?? BaseUnit::PIECE);
        $material->setReorderThreshold($input->reorder_threshold);
        $material->setStocktakeThreshold($input->stocktake_threshold);
        // OD-6 default: RAW materials require batch numbers on receipts.
        $material->setBatchRequired(MaterialType::RAW === MaterialType::from($input->type));

        $this->em->persist($material);
        $this->em->flush();

        return $material;
    }

    /**
     * @return Material|list<array{field:string, issue:string}>
     */
    public function update(Material $material, UpdateMaterialInput $input): Material|array
    {
        if (array_key_exists('base_unit', $input->raw)) {
            return [['field' => 'base_unit', 'issue' => 'base_unit is immutable and cannot be updated.']];
        }

        if (null !== $input->name) {
            if ('' === $input->name || mb_strlen($input->name) > 120) {
                return [['field' => 'name', 'issue' => 'must be 1..120 characters']];
            }
            $dup = $this->findDuplicate($input->name);
            if (null !== $dup && $dup->getId() !== $material->getId()) {
                return [['field' => 'name', 'issue' => 'A material with this name already exists.']];
            }
            $material->setName($input->name);
        }

        if (null !== $input->type) {
            $type = MaterialType::tryFrom($input->type);
            if (null === $type) {
                return [['field' => 'type', 'issue' => 'must be RAW or CONSUMABLE']];
            }
            $material->setType($type);
        }

        if (null !== $input->reorder_threshold) {
            if (!self::isValidQuantity($input->reorder_threshold)) {
                return [['field' => 'reorder_threshold', 'issue' => 'must be a decimal ≥ 0, at most 3 decimal places, ≤ 10000000.000']];
            }
            $material->setReorderThreshold($input->reorder_threshold);
        }

        if (null !== $input->stocktake_threshold) {
            if (!self::isValidQuantity($input->stocktake_threshold)) {
                return [['field' => 'stocktake_threshold', 'issue' => 'must be a decimal ≥ 0, at most 3 decimal places, ≤ 10000000.000']];
            }
            $material->setStocktakeThreshold($input->stocktake_threshold);
        }

        if (null !== $input->is_active) {
            $material->setIsActive($input->is_active);
        }

        $this->em->flush();

        return $material;
    }

    /**
     * @return array{items: list<Material>, total: int}
     */
    public function list(int $page, int $perPage, ?string $type, ?string $search, bool $includeInactive): array
    {
        $qb = $this->materials->createQueryBuilder('m')
            ->orderBy('m.name', 'ASC');

        if (null !== $type) {
            if (null === MaterialType::tryFrom($type)) {
                throw new \InvalidArgumentException('type must be RAW or CONSUMABLE');
            }
            $qb->andWhere('m.type = :type')->setParameter('type', MaterialType::from($type));
        }
        if (null !== $search && '' !== $search) {
            $qb->andWhere('LOWER(m.name) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }
        if (!$includeInactive) {
            $qb->andWhere('m.is_active = true');
        }

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
        $paginator = new Paginator($qb, true);

        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => \count($paginator),
        ];
    }

    private function findDuplicate(string $name): ?Material
    {
        return $this->materials->findByName($name);
    }

    public static function isValidQuantity(string $value): bool
    {
        if (1 !== preg_match('/^-?\d+(\.\d{1,3})?$/', $value)) {
            return false;
        }

        return bccomp($value, '10000000.000', 3) <= 0 && bccomp($value, '0.000', 3) >= 0;
    }
}
