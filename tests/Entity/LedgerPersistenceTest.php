<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AuditLog;
use App\Entity\Material;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\AuditOutcome;
use App\Enum\BaseUnit;
use App\Enum\MaterialType;
use App\Enum\MovementType;
use App\Enum\SourceDocumentType;
use App\Repository\StockMovementRepository;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class LedgerPersistenceTest extends KernelTestCase
{
    use ResetDatabase;

    private function makeUser(EntityManagerInterface $em, string $username = 'operator-a', string $role = 'OPERATOR'): User
    {
        $user = new User($username, \App\Enum\UserRole::from($role));
        $em->persist($user);

        return $user;
    }

    private function makeMaterial(EntityManagerInterface $em, string $name = 'Coffee beans'): Material
    {
        $material = new Material($name, MaterialType::RAW, BaseUnit::GRAM);
        $em->persist($material);

        return $material;
    }

    public function testLedgerSumReproducesStockOnHand(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var StockMovementRepository $movementRepo */
        $movementRepo = static::getContainer()->get(StockMovementRepository::class);

        $user = $this->makeUser($em);
        $material = $this->makeMaterial($em);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ([
            ['200.000', MovementType::RECEIPT],
            ['-55.500', MovementType::CONSUMPTION],
            ['-0.001', MovementType::WASTE],
            ['+1.250', MovementType::ADJUSTMENT],
        ] as $idx => [$qty, $type]) {
            $em->persist(new StockMovement(
                $material,
                $type,
                $qty,
                $now->modify("+$idx seconds"),
                SourceDocumentType::CONSUMPTION_ENTRY,
                $idx + 1,
                $user,
            ));
        }
        $em->flush();
        $em->clear();

        self::assertSame('145.749', $movementRepo->getStockOnHand($material->getId() ?? 0));
    }

    public function testDecimalLedgerSumIsExactNotFloatDrift(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var StockMovementRepository $movementRepo */
        $movementRepo = static::getContainer()->get(StockMovementRepository::class);

        $user = $this->makeUser($em);
        $material = $this->makeMaterial($em);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // 0.1 + 0.2 = exactly 0.3 in decimal arithmetic (REQ-010 acceptance criterion).
        foreach (['0.100', '0.200'] as $idx => $qty) {
            $em->persist(new StockMovement(
                $material,
                MovementType::ADJUSTMENT,
                $qty,
                $now->modify("+$idx seconds"),
                SourceDocumentType::CONSUMPTION_ENTRY,
                $idx + 1,
                $user,
            ));
        }
        $em->flush();
        $em->clear();

        $sum = $movementRepo->getStockOnHand($material->getId() ?? 0);
        self::assertSame('0.300', $sum, 'ledger sum must be exactly 0.300, never 0.30000000000000004');
    }

    public function testMovementRequiresAttributionNotNullUser(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $material = $this->makeMaterial($em);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Try to persist a movement without a valid user attribution via raw DBAL
        // (em would refuse null at PHP level; bypass via connection to test the DB rule).
        $conn = $em->getConnection();
        $this->expectException(NotNullConstraintViolationException::class);
        $conn->insert('stock_movement', [
            'material_id' => $material->getId() ?? 0,
            'movement_type' => 'RECEIPT',
            'signed_quantity' => '1.000',
            'occurred_at' => $now,
            'source_document_type' => 'RECEIPT_ITEM',
            'source_document_id' => 1,
            'created_by_user_id' => null, // forbidden
            'batch_id' => null,
        ], ['occurred_at' => 'datetime_immutable']);
    }

    public function testAuditLogDetailJsonbRoundTrip(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = $this->makeUser($em);
        $detail = ['reason' => 'over-tolerance delta', 'delta' => '-2.123', 'counts' => [1 => true]];
        $audit = new AuditLog($user, AuditAction::STOCKTAKE_COMMIT, 'stocktake', 7, AuditOutcome::SUCCESS, $detail);
        $em->persist($audit);
        $em->flush();
        $em->clear();

        /** @var AuditLog $found */
        $found = $em->find(AuditLog::class, $audit->getId() ?? 0);
        self::assertSame(AuditAction::STOCKTAKE_COMMIT, $found->getAction());
        self::assertSame(AuditOutcome::SUCCESS, $found->getOutcome());
        self::assertSame($detail, $found->getDetail());
    }
}
