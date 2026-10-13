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
 * REQ-050/BR-17: signed adjustments with mandatory reason; OD-1 zero floor
 * and OD-5 threshold enforced through the explicit `approved` flag.
 */
final class AdjustmentApiTest extends WebTestCase
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

    private function makeMaterial(string $name, ?string $stocktakeThreshold = null): Material
    {
        $material = new Material($name, MaterialType::CONSUMABLE, BaseUnit::GRAM);
        if (null !== $stocktakeThreshold) {
            $material->setStocktakeThreshold($stocktakeThreshold);
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
     * @param array<string, mixed> $extra
     */
    private function adjust(Material $material, string $delta, array $extra = []): void
    {
        $this->client()->request('POST', '/api/adjustments', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(array_merge([
            'material_id' => $material->getId(),
            'signed_delta' => $delta,
            'reason' => 'Cycle count correction',
        ], $extra), JSON_THROW_ON_ERROR));
    }

    public function testAdjustmentRequiresReason(): void
    {
        $beans = $this->makeMaterial('Beans-reason-'.uniqid());

        $this->client()->request('POST', '/api/adjustments', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $beans->getId(),
            'signed_delta' => '2.000',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdjustmentRequiresNonZeroDelta(): void
    {
        $beans = $this->makeMaterial('Beans-zero-'.uniqid());

        $this->adjust($beans, '0.000');
        self::assertResponseStatusCodeSame(422);
    }

    public function testSignedAdjustmentsMoveStockBothWays(): void
    {
        $beans = $this->makeMaterial('Beans-signed-'.uniqid());
        $this->seedStock($beans, '10.000');

        $this->adjust($beans, '-2.500');
        self::assertResponseStatusCodeSame(201, (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('-2.500', $body['signed_delta']);
        self::assertNull($body['approval_id']);

        $this->client()->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('7.500', $this->json()['stock_on_hand']);

        $this->adjust($beans, '1.000', ['reason' => 'Restock found in store room']);
        self::assertResponseStatusCodeSame(201);
        $this->client()->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('8.500', $this->json()['stock_on_hand']);

        // Ledger carries both ADJUSTMENT rows with reason traceability on the source doc.
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT movement_type, signed_quantity FROM stock_movement WHERE material_id = :m AND movement_type = :t ORDER BY id',
            ['m' => $beans->getId(), 't' => 'ADJUSTMENT'],
        );
        self::assertCount(2, $rows);
        self::assertSame('-2.500', $rows[0]['signed_quantity']);

        $audit = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT action FROM audit_log WHERE action = :a',
            ['a' => 'ADJUSTMENT_CREATE'],
        );
        self::assertNotEmpty($audit);
    }

    public function testNegativeStockBlockedWithoutApproval(): void
    {
        $beans = $this->makeMaterial('Beans-floor-adj-'.uniqid());
        $this->seedStock($beans, '1.000');

        $this->adjust($beans, '-5.000');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('NEGATIVE_STOCK_BLOCKED', $this->json()['error']['code']);

        $this->client()->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('1.000', $this->json()['stock_on_hand']);
    }

    public function testNegativeStockAllowedWithApprovedFlag(): void
    {
        $beans = $this->makeMaterial('Beans-approved-'.uniqid());
        $this->seedStock($beans, '1.000');

        $this->adjust($beans, '-5.000', ['approved' => true, 'reason' => 'Spillage during decanting']);

        self::assertResponseStatusCodeSame(201, (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertNotNull($body['approval_id'], 'OD-1 override must record an approval row');

        $this->client()->request('GET', '/api/inventory/'.$beans->getId());
        self::assertSame('-4.000', $this->json()['stock_on_hand']);
    }

    public function testThresholdExceededRequiresApproval(): void
    {
        $beans = $this->makeMaterial('Beans-threshold-'.uniqid(), '1.000');
        $this->seedStock($beans, '10.000');

        // |delta| 2.000 > threshold 1.000 → 409 APPROVAL_REQUIRED (OD-5).
        $this->adjust($beans, '2.000');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('APPROVAL_REQUIRED', $this->json()['error']['code']);

        // Same delta with the approved flag lands with an approval row.
        $this->adjust($beans, '2.000', ['approved' => true]);
        self::assertResponseStatusCodeSame(201);
        self::assertNotNull($this->json()['approval_id']);

        // Within threshold: no approval needed.
        $this->adjust($beans, '0.500');
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->json()['approval_id']);
    }
}
