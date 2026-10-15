<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Supplier;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SupplierPersistenceTest extends KernelTestCase
{
    use ResetDatabase;

    public function testSupplierPersistsContactFieldsAndDeactivation(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $supplier = new Supplier('Roastery Co');
        $supplier->setContactName('Ann');
        $supplier->setEmail('ann@roastery.example');
        $supplier->setPhone('+1234');

        $em->persist($supplier);
        $em->flush();
        $em->clear();

        /** @var Supplier $found */
        $found = $em->find(Supplier::class, $supplier->getId() ?? 0);
        self::assertSame('Roastery Co', $found->getName());
        self::assertSame('Ann', $found->getContactName());
        self::assertSame('ann@roastery.example', $found->getEmail());
        self::assertSame('+1234', $found->getPhone());
        self::assertTrue($found->isActive());

        $found->setIsActive(false);
        $em->flush();
        $em->clear();

        /** @var Supplier $reloaded */
        $reloaded = $em->find(Supplier::class, $found->getId() ?? 0);
        self::assertFalse($reloaded->isActive());
        self::assertInstanceOf(\DateTimeImmutable::class, $reloaded->getUpdatedAt());
    }

    public function testDuplicateSupplierNameIsRejected(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $em->persist(new Supplier('Roastery Co'));
        $em->flush();

        $em->persist(new Supplier('Roastery Co'));

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testSupplierWithOnlyNameIsValid(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $supplier = new Supplier('Minimal Supplier');
        $em->persist($supplier);
        $em->flush();
        $em->clear();

        /** @var Supplier $found */
        $found = $em->find(Supplier::class, $supplier->getId() ?? 0);
        self::assertNull($found->getContactName());
        self::assertNull($found->getEmail());
        self::assertNull($found->getPhone());
    }
}
