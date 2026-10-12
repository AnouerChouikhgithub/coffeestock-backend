<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Schema-level guarantee for INV-2 (append-only ledger): FKs pointing INTO
 * stock_movement must never carry ON UPDATE CASCADE or ON DELETE CASCADE/SET NULL —
 * no mutation or removal path may exist that could reshape the ledger subtly.
 */
final class LedgerAppendOnlySchemaTest extends KernelTestCase
{
    use ResetDatabase;

    public function testNoFkIntoStockMovementCarriesUpdateOrDeleteAction(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();

        $rows = $conn->fetchAllAssociative(<<<'SQL'
            SELECT
                tc.table_name,
                kcu.column_name,
                rc.update_rule,
                rc.delete_rule
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
            JOIN information_schema.referential_constraints rc
              ON rc.constraint_name = tc.constraint_name
             AND rc.constraint_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = 'public'
              AND rc.unique_constraint_name IN (
                SELECT tc2.constraint_name
                FROM information_schema.table_constraints tc2
                JOIN information_schema.key_column_usage kcu2
                  ON tc2.constraint_name = kcu2.constraint_name
                 AND tc2.table_schema = kcu2.table_schema
                WHERE tc2.constraint_type IN ('PRIMARY KEY', 'UNIQUE')
                  AND tc2.table_schema = 'public'
                  AND tc2.table_name = 'stock_movement'
              )
        SQL);

        self::assertNotEmpty($rows, 'expected at least one FK referencing stock_movement');

        $bad = array_filter($rows, static fn (array $r): bool => 'NO ACTION' !== $r['update_rule'] || 'NO ACTION' !== $r['delete_rule']);

        self::assertSame(
            [],
            $bad,
            'Every FK into stock_movement must have NO ACTION for both update and delete rules; got: '.json_encode($bad),
        );
    }
}
