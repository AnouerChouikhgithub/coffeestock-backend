<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Material;
use App\Enum\BaseUnit;
use App\Enum\MaterialType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ReceiptApiTest extends WebTestCase
{
    use ResetDatabase;
    use \App\Tests\AuthenticatedTrait;

    private ?KernelBrowser $cachedClient = null;

    private function client(): KernelBrowser
    {
        return $this->cachedClient ??= $this->authClient();
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

    private function makeSupplier(string $name = 'Roastery Co', bool $active = true): int
    {
        $id = $this->client()->request('POST', '/api/suppliers', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => $name], JSON_THROW_ON_ERROR));
        $id = (int) $this->json()['id'];
        if (!$active) {
            $em = $this->em();
            $supplier = $em->getRepository(\App\Entity\Supplier::class)->find($id);
            if ($supplier instanceof \App\Entity\Supplier) {
                $supplier->setIsActive(false);
                $em->persist($supplier);
                $em->flush();
            }
        }

        return $id;
    }

    private function makeMaterial(string $name, MaterialType $type, BaseUnit $unit, bool $active = true): Material
    {
        $material = new Material($name, $type, $unit);
        $material->setIsActive($active);
        if (MaterialType::RAW === $type) {
            $material->setBatchRequired(true); // OD-6 default as set by POST /api/materials
        }
        $this->em()->persist($material);
        $this->em()->flush();

        return $material;
    }

    public function testReceiptCreatesExactlyOnePositiveMovementPerItem(): void
    {
        $client = $this->client();
        $supplierId = $this->makeSupplier();
        $beans = $this->makeMaterial('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $cups = $this->makeMaterial('Cups', MaterialType::CONSUMABLE, BaseUnit::PIECE);

        $client->request('POST', '/api/receipts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'supplier_id' => $supplierId,
            'delivery_date' => '2026-10-10',
            'items' => [
                ['material_id' => $beans->getId(), 'quantity' => '12.500', 'unit_cost' => '3.200', 'batch' => ['batch_number' => 'RO-041']],
                ['material_id' => $cups->getId(), 'quantity' => '200'],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertCount(2, $body['items']);
        self::assertSame('12.500', $body['items'][0]['quantity']);
        self::assertSame('3.200', $body['items'][0]['unit_cost']);
        self::assertNotNull($body['items'][0]['batch_id']);
        self::assertIsInt($body['items'][0]['stock_movement_id']);
        self::assertNull($body['items'][1]['batch_id']); // consumable, no batch supplied

        // Ledger: exactly 2 RECEIPT rows, positive, equal to item quantities (REQ-031)
        $movements = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT movement_type, signed_quantity, source_document_type, source_document_id FROM stock_movement ORDER BY id',
        );
        self::assertCount(2, $movements);
        self::assertSame('RECEIPT', $movements[0]['movement_type']);
        self::assertSame('12.500', $movements[0]['signed_quantity']);
        self::assertSame('RECEIPT_ITEM', $movements[0]['source_document_type']);

        // Reconciliation: derived stock equals item quantities (INV-3)
        $client->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('12.500', $this->json()['stock_on_hand']);
        $client->request('GET', '/api/inventory/'.$cups->getId());
        self::assertSame('200.000', $this->json()['stock_on_hand']);
    }

    public function testFutureDeliveryDateIs422(): void
    {
        $client = $this->client();
        $supplierId = $this->makeSupplier();
        $beans = $this->makeMaterial('Beans', MaterialType::RAW, BaseUnit::GRAM);

        $client->request('POST', '/api/receipts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'supplier_id' => $supplierId,
            'delivery_date' => gmdate('Y-m-d', time() + 86400),
            'items' => [['material_id' => $beans->getId(), 'quantity' => '1.000']],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('VALIDATION_FAILED', $this->json()['error']['code']);
    }

    public function testEmptyItemsIs422(): void
    {
        $client = $this->client();
        $supplierId = $this->makeSupplier();

        $client->request('POST', '/api/receipts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'supplier_id' => $supplierId,
            'delivery_date' => '2026-10-10',
            'items' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testInactiveSupplierIs422(): void
    {
        $client = $this->client();
        $supplierId = $this->makeSupplier('Gone Co', active: false);
        $beans = $this->makeMaterial('Beans', MaterialType::RAW, BaseUnit::GRAM);

        $client->request('POST', '/api/receipts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'supplier_id' => $supplierId,
            'delivery_date' => '2026-10-10',
            'items' => [['material_id' => $beans->getId(), 'quantity' => '1.000', 'batch' => ['batch_number' => 'X']]],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('SUPPLIER_INACTIVE', $this->json()['error']['code']);
    }

    public function testInactiveMaterialIs422(): void
    {
        $client = $this->client();
        $supplierId = $this->makeSupplier();
        $beans = $this->makeMaterial('Beans', MaterialType::RAW, BaseUnit::GRAM, active: false);

        $client->request('POST', '/api/receipts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'supplier_id' => $supplierId,
            'delivery_date' => '2026-10-10',
            'items' => [['material_id' => $beans->getId(), 'quantity' => '1.000', 'batch' => ['batch_number' => 'X']]],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('MATERIAL_INACTIVE', $this->json()['error']['code']);
    }

    public function testRawMaterialWithoutBatchNumberIs422(): void
    {
        $client = $this->client();
        $supplierId = $this->makeSupplier();
        $beans = $this->makeMaterial('Beans', MaterialType::RAW, BaseUnit::GRAM);

        $client->request('POST', '/api/receipts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'supplier_id' => $supplierId,
            'delivery_date' => '2026-10-10',
            'items' => [['material_id' => $beans->getId(), 'quantity' => '1.000']], // no batch for batch-required material
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('VALIDATION_FAILED', $this->json()['error']['code']);
    }

    public function testInvalidQuantitiesAre422(): void
    {
        $client = $this->client();
        $supplierId = $this->makeSupplier();
        $cups = $this->makeMaterial('Cups', MaterialType::CONSUMABLE, BaseUnit::PIECE);

        foreach (['0.000', '1.2345', '-3.000'] as $qty) {
            $client->request('POST', '/api/receipts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'supplier_id' => $supplierId,
                'delivery_date' => '2026-10-10',
                'items' => [['material_id' => $cups->getId(), 'quantity' => $qty]],
            ], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, "quantity {$qty} must be rejected");
        }
    }

    public function testUnknownSupplierIs404(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/receipts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'supplier_id' => 999999,
            'delivery_date' => '2026-10-10',
            'items' => [['material_id' => 1, 'quantity' => '1.000']],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404);
    }

    public function testReceiptListFiltersAndItemCounts(): void
    {
        $client = $this->client();
        $supplierId = $this->makeSupplier();
        $beans = $this->makeMaterial('Beans', MaterialType::RAW, BaseUnit::GRAM);

        $client->request('POST', '/api/receipts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'supplier_id' => $supplierId,
            'delivery_date' => '2026-10-01',
            'items' => [['material_id' => $beans->getId(), 'quantity' => '5.000', 'batch' => ['batch_number' => 'A']]],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, 'receipt creation failed: '.(string) $client->getResponse()->getContent());

        $client->request('GET', '/api/receipts');
        $body = $this->json();
        self::assertSame(1, $body['total']);
        self::assertSame(1, $body['items'][0]['item_count']);

        // date filter excludes it
        $client->request('GET', '/api/receipts?date_from=2026-10-05');
        self::assertSame(0, $this->json()['total']);

        // material filter includes it
        $client->request('GET', '/api/receipts?material_id='.$beans->getId());
        self::assertSame(1, $this->json()['total']);
    }

    public function testReceiptsAreImmutableNoPatchRoute(): void
    {
        $client = $this->client();
        $supplierId = $this->makeSupplier();
        $cups = $this->makeMaterial('Cups', MaterialType::CONSUMABLE, BaseUnit::PIECE);

        $client->request('POST', '/api/receipts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'supplier_id' => $supplierId,
            'delivery_date' => '2026-10-10',
            'items' => [['material_id' => $cups->getId(), 'quantity' => '3.000']],
        ], JSON_THROW_ON_ERROR));
        $id = $this->json()['id'];

        // No PATCH/DELETE endpoints exist for receipts (REQ-032 immutability)
        $client->request('PATCH', "/api/receipts/{$id}", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(405);
        $client->request('DELETE', "/api/receipts/{$id}");
        self::assertResponseStatusCodeSame(405);
    }
}
