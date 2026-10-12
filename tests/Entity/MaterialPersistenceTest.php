<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Enum\BaseUnit;
use App\Enum\MaterialType;
use App\Entity\Material;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class MaterialPersistenceTest extends KernelTestCase
{
    use ResetDatabase;

    public function testMaterialPersistsAllFields(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $material = new Material('Espresso beans', MaterialType::RAW, BaseUnit::GRAM);
        $material->setReorderThreshold('500.000');
        $material->setStocktakeThreshold('2.000');
        $material->setBatchRequired(true);

        $em->persist($material);
        $em->flush();
        $em->clear();

        $found = $em->find(Material::class, $material->getId() ?? 0);
        self::assertNotNull($found);
        self::assertSame('Espresso beans', $found->getName());
        self::assertSame(MaterialType::RAW, $found->getType());
        self::assertSame(BaseUnit::GRAM, $found->getBaseUnit());
        self::assertSame('500.000', $found->getReorderThreshold());
        self::assertSame('2.000', $found->getStocktakeThreshold());
        self::assertTrue($found->isBatchRequired());
        self::assertTrue($found->isActive());
    }

    public function testDecimalRoundTripPreservesScaleThreeExactly(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $small = new Material('Saffron', MaterialType::RAW, BaseUnit::GRAM);
        $small->setReorderThreshold('0.001');
        $large = new Material('Beans bulk', MaterialType::RAW, BaseUnit::GRAM);
        $large->setReorderThreshold('12345678.123');

        $em->persist($small);
        $em->persist($large);
        $em->flush();
        $em->clear();

        /** @var Material $small */
        $small = $em->find(Material::class, $small->getId() ?? 0);
        /** @var Material $large */
        $large = $em->find(Material::class, $large->getId() ?? 0);

        // Exact string equality: floats would fail this (REQ-010).
        self::assertSame('0.001', $small->getReorderThreshold());
        self::assertSame('12345678.123', $large->getReorderThreshold());
        self::assertNotSame('0.001', $small->getReorderThreshold() + 0.0); // never a float
        self::assertIsString($small->getReorderThreshold());
    }

    public function testDuplicateMaterialNameIsRejectedByUniqueConstraint(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $em->persist(new Material('Milk', MaterialType::RAW, BaseUnit::MILLILITRE));
        $em->flush();

        $em->persist(new Material('Milk', MaterialType::CONSUMABLE, BaseUnit::PIECE));

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testNullThresholdsPersistAsNullNotZero(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $material = new Material('Cups', MaterialType::CONSUMABLE, BaseUnit::PIECE);
        $em->persist($material);
        $em->flush();
        $em->clear();

        /** @var Material $found */
        $found = $em->find(Material::class, $material->getId() ?? 0);
        self::assertNull($found->getReorderThreshold());
        self::assertNull($found->getStocktakeThreshold());
    }
}
