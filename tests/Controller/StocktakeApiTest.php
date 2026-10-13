<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Material;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\BaseUnit;
use App\Enum\MaterialType;
use App\Enum\MovementType;
use App\Enum\SourceDocumentType;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * REQ-051/052: stocktakes snapshot derived stock at draft creation, compute
 * delta = counted − derived, and commit atomically as STOCKTAKE_DELTA
 * movements. OD-5: over-tolerance counts require an approval entry (INV-10).
 */
final class StocktakeApiTest extends WebTestCase
{
    use ResetDatabase;

    private ?KernelBrowser $cachedClient = null;

    protected function tearDown(): void
    {
        $this->cachedClient = null;
        parent::tearDown();
    }

    private function client(): KernelBrowser
    {
        return $this->cachedClient ??= self::createClient();
    }

    private function em(): EntityManagerInterface
    {
        return $this->client()->getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $content = (string) $this->client()->getResponse()->getContent();
        self::assertNotSame('', $content, 'expected a JSON body');

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function makeMaterial(string $name, ?string $threshold = null): Material
    {
        $material = new Material($name, MaterialType::CONSUMABLE, BaseUnit::GRAM);
        if (null !== $threshold) {
            $material->setStocktakeThreshold($threshold);
        }
        $this->em()->persist($material);
        $this->em()->flush();

        return $material;
    }

    private function seedStock(Material $material, string $qty): void
    {
        $user = new User('seed-'.uniqid(), UserRole::OPERATOR);
        $this->em()->persist($user);
        $movement = new StockMovement(
            $material,
            MovementType::RECEIPT,
            $qty,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            SourceDocumentType::RECEIPT_ITEM,
            0,
            $user,
        );
        $this->em()->persist($movement);
        $this->em()->flush();
    }

    /**
     * @param list<array{material_id: int|null, counted_quantity: string}> $counts
     */
    private function createDraft(array $counts): void
    {
        $this->client()->request('POST', '/api/stocktakes', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'counts' => $counts,
        ], JSON_THROW_ON_ERROR));
    }

    public function testDraftSnapshotsDerivedStockAndDelta(): void
    {
        $beans = $this->makeMaterial('Beans-st-'.uniqid());
        $this->seedStock($beans, '10.000');
        // Movement after the snapshot must not change the frozen delta.
        $this->client()->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $beans->getId(),
            'quantity' => '1.000',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->createDraft([['material_id' => $beans->getId(), 'counted_quantity' => '8.500']]);

        self::assertResponseStatusCodeSame(201, (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('DRAFT', $body['status']);
        self::assertCount(1, $body['counts']);
        self::assertSame('9.000', $body['counts'][0]['derived_stock_at_count']);
        self::assertSame('-0.500', $body['counts'][0]['delta']);
        self::assertNull($body['counts'][0]['reconciliation_movement_id']);

        // Draft writes nothing to the ledger.
        $this->client()->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('9.000', $this->json()['stock_on_hand']);

        // Readable via GET.
        $this->client()->request('GET', '/api/stocktakes/'.$body['id']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('DRAFT', $this->json()['status']);
    }

    public function testValidationFailures(): void
    {
        $beans = $this->makeMaterial('Beans-st-valid-'.uniqid());
        $this->seedStock($beans, '10.000');

        // Empty counts.
        $this->createDraft([]);
        self::assertResponseStatusCodeSame(422);

        // Unknown material → 404.
        $this->createDraft([['material_id' => 999999, 'counted_quantity' => '1.000']]);
        self::assertResponseStatusCodeSame(404);

        // Negative counted quantity.
        $this->createDraft([['material_id' => $beans->getId(), 'counted_quantity' => '-1.000']]);
        self::assertResponseStatusCodeSame(422);

        // Duplicate material.
        $this->createDraft([
            ['material_id' => $beans->getId(), 'counted_quantity' => '1.000'],
            ['material_id' => $beans->getId(), 'counted_quantity' => '2.000'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCommitWithinToleranceNeedsNoApproval(): void
    {
        $beans = $this->makeMaterial('Beans-tol-'.uniqid());
        $this->seedStock($beans, '100.000');

        $this->createDraft([['material_id' => $beans->getId(), 'counted_quantity' => '99.500']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'];
        $countId = $this->json()['counts'][0]['id'];

        $this->client()->request('POST', "/api/stocktakes/{$id}/commit", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(200, (string) $this->client()->getResponse()->getContent());

        $body = $this->json();
        self::assertSame('COMMITTED', $body['status']);
        self::assertNotNull($body['counts'][0]['reconciliation_movement_id']);

        // Ledger moved to the counted value; movement references the count (INV-9).
        $this->client()->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('99.500', $this->json()['stock_on_hand']);

        $movement = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT signed_quantity, source_document_type, source_document_id FROM stock_movement WHERE movement_type = :t',
            ['t' => 'STOCKTAKE_DELTA'],
        );
        self::assertCount(1, $movement);
        self::assertSame('-0.500', $movement[0]['signed_quantity']);
        self::assertSame('STOCKTAKE_COUNT', $movement[0]['source_document_type']);
        self::assertSame((string) $countId, (string) $movement[0]['source_document_id']);

        $audit = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT action FROM audit_log WHERE action = :a',
            ['a' => 'STOCKTAKE_COMMIT'],
        );
        self::assertNotEmpty($audit);
    }

    public function testOverToleranceCommitRequiresApproval(): void
    {
        $beans = $this->makeMaterial('Beans-tol-hi-'.uniqid());
        $this->seedStock($beans, '100.000');

        // Default tolerance = 2% of 100.000 = 2.000; delta -5.000 exceeds it.
        $this->createDraft([['material_id' => $beans->getId(), 'counted_quantity' => '95.000']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'];
        $countId = $this->json()['counts'][0]['id'];

        $this->client()->request('POST', "/api/stocktakes/{$id}/commit", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('APPROVAL_REQUIRED', $this->json()['error']['code']);

        // Still a draft; nothing posted.
        $this->client()->request('GET', '/api/stocktakes/'.$id);
        self::assertSame('DRAFT', $this->json()['status']);
        $this->client()->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('100.000', $this->json()['stock_on_hand']);

        // Retry with the approval entry.
        $this->client()->request('POST', "/api/stocktakes/{$id}/commit", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'approvals' => [['count_id' => $countId, 'approver_comment' => 'Shrinkage agreed with manager']],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200, (string) $this->client()->getResponse()->getContent());
        self::assertSame('COMMITTED', $this->json()['status']);

        $this->client()->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('95.000', $this->json()['stock_on_hand']);

        $approval = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT target_entity, comment FROM approval',
        );
        self::assertNotEmpty($approval);
        self::assertSame('stocktake_count', $approval[0]['target_entity']);
    }

    public function testMaterialThresholdOverridesDefaultTolerance(): void
    {
        $beans = $this->makeMaterial('Beans-tol-cfg-'.uniqid(), '10.000');
        $this->seedStock($beans, '100.000');

        // Default 2% would be 2.000, but the material threshold is 10.000 → within tolerance.
        $this->createDraft([['material_id' => $beans->getId(), 'counted_quantity' => '95.000']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'];

        $this->client()->request('POST', "/api/stocktakes/{$id}/commit", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(200, (string) $this->client()->getResponse()->getContent());
    }

    public function testZeroDeltaCountNeedsNoMovement(): void
    {
        $beans = $this->makeMaterial('Beans-zero-delta-'.uniqid());
        $this->seedStock($beans, '10.000');

        $this->createDraft([['material_id' => $beans->getId(), 'counted_quantity' => '10.000']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'];

        $this->client()->request('POST', "/api/stocktakes/{$id}/commit", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['counts'][0]['reconciliation_movement_id']);

        $movement = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT COUNT(*) AS c FROM stock_movement WHERE movement_type = :t',
            ['t' => 'STOCKTAKE_DELTA'],
        );
        self::assertSame(0, (int) $movement[0]['c']);
    }

    public function testRecommitIs409StocktakeNotDraft(): void
    {
        $beans = $this->makeMaterial('Beans-recommit-'.uniqid());
        $this->seedStock($beans, '10.000');

        $this->createDraft([['material_id' => $beans->getId(), 'counted_quantity' => '9.900']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'];

        $this->client()->request('POST', "/api/stocktakes/{$id}/commit", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(200);

        $this->client()->request('POST', "/api/stocktakes/{$id}/commit", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('STOCKTAKE_NOT_DRAFT', $this->json()['error']['code']);
    }

    public function testDownwardDeltaRespectsZeroFloor(): void
    {
        $beans = $this->makeMaterial('Beans-floor-st-'.uniqid());
        $this->seedStock($beans, '10.000');

        $this->createDraft([['material_id' => $beans->getId(), 'counted_quantity' => '1.000']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'];
        $countId = $this->json()['counts'][0]['id'];

        // Consume 9.5 after the draft so committing delta -9.000 would floor-cross.
        $this->client()->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $beans->getId(),
            'quantity' => '9.500',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->client()->request('POST', "/api/stocktakes/{$id}/commit", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'approvals' => [['count_id' => $countId, 'approver_comment' => 'Large correction']],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NEGATIVE_STOCK_BLOCKED', $this->json()['error']['code']);
    }

    public function testUnknownStocktakeIs404(): void
    {
        $this->client()->request('GET', '/api/stocktakes/999999');
        self::assertResponseStatusCodeSame(404);

        $this->client()->request('POST', '/api/stocktakes/999999/commit', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(404);
    }
}
