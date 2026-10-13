<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Material;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\BaseUnit;
use App\Enum\MovementType;
use App\Enum\MaterialType;
use App\Enum\SourceDocumentType;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * REQ-040/041: consumption and waste create exactly one negative ledger
 * movement each, of distinct types, with the OD-1 zero floor enforced.
 */
final class UsageApiTest extends WebTestCase
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

    private function makeMaterial(string $name, MaterialType $type = MaterialType::CONSUMABLE, BaseUnit $unit = BaseUnit::GRAM): Material
    {
        $material = new Material($name, $type, $unit);
        $this->em()->persist($material);
        $this->em()->flush();

        return $material;
    }

    private function seedUser(): User
    {
        $user = new User('seed-'.uniqid(), UserRole::OPERATOR);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** Direct positive ledger entry so usage tests do not depend on the receipts API. */
    private function seedStock(Material $material, string $qty): void
    {
        $movement = new StockMovement(
            $material,
            MovementType::RECEIPT,
            $qty,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            SourceDocumentType::RECEIPT_ITEM,
            0,
            $this->seedUser(),
        );
        $this->em()->persist($movement);
        $this->em()->flush();
    }

    public function testConsumptionRecordsNegativeMovementAndAudit(): void
    {
        $beans = $this->makeMaterial('Beans-'.uniqid());
        $this->seedStock($beans, '10.000');

        $client = $this->client();
        $client->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $beans->getId(),
            'quantity' => '2.500',
            'note' => 'Latte service',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('2.500', $body['quantity']);
        self::assertSame($beans->getId(), $body['material_id']);
        self::assertIsInt($body['stock_movement_id']);

        // Ledger: exactly one CONSUMPTION row, negative, provenance back-filled (INV-4/INV-9).
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT movement_type, signed_quantity, source_document_type, source_document_id FROM stock_movement WHERE material_id = :m ORDER BY id',
            ['m' => $beans->getId()],
        );
        self::assertCount(2, $rows); // seeded receipt + consumption
        self::assertSame('CONSUMPTION', $rows[1]['movement_type']);
        self::assertSame('-2.500', $rows[1]['signed_quantity']);
        self::assertSame('CONSUMPTION_ENTRY', $rows[1]['source_document_type']);
        self::assertSame((string) $body['id'], (string) $rows[1]['source_document_id']);

        // Derived stock reflects the ledger (INV-3).
        $client->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('7.500', $this->json()['stock_on_hand']);

        // Audit row (REQ-110).
        $audit = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT action, target_entity FROM audit_log WHERE action = :a',
            ['a' => 'CONSUMPTION_CREATE'],
        );
        self::assertNotEmpty($audit);
        self::assertSame('consumption_entry', $audit[0]['target_entity']);
    }

    public function testConsumptionUnknownMaterialIs404(): void
    {
        $this->client()->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => 999999,
            'quantity' => '1.000',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('NOT_FOUND', $this->json()['error']['code']);
    }

    public function testConsumptionInvalidQuantitiesAre422(): void
    {
        $cups = $this->makeMaterial('Cups-'.uniqid(), MaterialType::CONSUMABLE, BaseUnit::PIECE);
        $this->seedStock($cups, '100.000');

        foreach (['0.000', '-3.000', '1.2345'] as $qty) {
            $this->client()->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'material_id' => $cups->getId(),
                'quantity' => $qty,
            ], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, "quantity {$qty} must be rejected");
        }
    }

    public function testFutureRecordedAtIs422(): void
    {
        $beans = $this->makeMaterial('Beans-future-'.uniqid());
        $this->seedStock($beans, '10.000');

        $this->client()->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $beans->getId(),
            'quantity' => '1.000',
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testConsumptionBlockedWhenStockWouldGoNegative(): void
    {
        $beans = $this->makeMaterial('Beans-low-'.uniqid());
        $this->seedStock($beans, '1.000');

        $this->client()->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $beans->getId(),
            'quantity' => '5.000',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('NEGATIVE_STOCK_BLOCKED', $this->json()['error']['code']);

        // Nothing was written.
        $this->client()->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('1.000', $this->json()['stock_on_hand']);
    }

    public function testExactFloorIsAllowed(): void
    {
        $beans = $this->makeMaterial('Beans-floor-'.uniqid());
        $this->seedStock($beans, '5.000');

        $this->client()->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $beans->getId(),
            'quantity' => '5.000',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $this->client()->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('0.000', $this->json()['stock_on_hand']);
    }

    public function testWasteRequiresReason(): void
    {
        $beans = $this->makeMaterial('Beans-waste-nr-'.uniqid());
        $this->seedStock($beans, '10.000');

        $this->client()->request('POST', '/api/wastes', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $beans->getId(),
            'quantity' => '1.000',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testWasteRecordsDistinctLedgerType(): void
    {
        $beans = $this->makeMaterial('Beans-waste-'.uniqid());
        $this->seedStock($beans, '10.000');

        $this->client()->request('POST', '/api/wastes', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $beans->getId(),
            'quantity' => '3.250',
            'note' => 'Spoiled milk',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201, (string) $this->client()->getResponse()->getContent());

        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT movement_type, signed_quantity FROM stock_movement WHERE material_id = :m AND movement_type = :t',
            ['m' => $beans->getId(), 't' => 'WASTE'],
        );
        self::assertCount(1, $rows);
        self::assertSame('-3.250', $rows[0]['signed_quantity']);

        // Waste and consumption stay separable in history (BR-14/BR-15).
        $this->client()->request('GET', '/api/inventory/'.$beans->getId().'/movements?movement_type=CONSUMPTION');
        self::assertSame(0, $this->json()['total']);

        $audit = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT action FROM audit_log WHERE action = :a',
            ['a' => 'WASTE_CREATE'],
        );
        self::assertNotEmpty($audit);
    }

    public function testInactiveMaterialIs422(): void
    {
        $beans = $this->makeMaterial('Beans-inactive-'.uniqid());
        $beans->setIsActive(false);
        $this->em()->flush();

        $this->client()->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $beans->getId(),
            'quantity' => '1.000',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('MATERIAL_INACTIVE', $this->json()['error']['code']);
    }
}
