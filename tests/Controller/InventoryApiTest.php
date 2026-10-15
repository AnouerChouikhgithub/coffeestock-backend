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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class InventoryApiTest extends WebTestCase
{
    use ResetDatabase;
    use \App\Tests\AuthenticatedTrait;

    private ?User $user = null;

    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $cachedClient = null;

    /**
     * Ensures the test kernel boots through createClient exactly once per test,
     * before any other kernel-booting access (e.g. the entity manager).
     */
    /**
     * Ensures the test kernel boots exactly once per test, before any other
     * kernel-booting access (e.g. the entity manager). Authenticated per Phase 9.
     */
    private function client(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        return $this->cachedClient ??= $this->authClient();
    }

    private function em(): EntityManagerInterface
    {
        return $this->client()->getContainer()->get(EntityManagerInterface::class);
    }

    private function user(): User
    {
        if (null === $this->user) {
            $user = new User('seed', UserRole::OPERATOR);
            $this->em()->persist($user);
            $this->em()->flush();
            $this->user = $user;
        }

        return $this->user;
    }

    private function seedMovement(Material $m, string $qty, MovementType $type, string $at, int $docRef = 1): StockMovement
    {
        $mv = new StockMovement($m, $type, $qty, new \DateTimeImmutable($at), SourceDocumentType::CONSUMPTION_ENTRY, $docRef, $this->user());
        $this->em()->persist($mv);

        return $mv;
    }

    public function testInventoryOverviewMatchesLedgerSums(): void
    {
        $beans = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $beans->setReorderThreshold('10.000');
        $this->em()->persist($beans);

        // + 10, − 2.5, + 0.125 = 7.625 (below threshold 10)
        $this->seedMovement($beans, '10.000', MovementType::RECEIPT, '2026-10-01T08:00:00Z', 1);
        $this->seedMovement($beans, '-2.500', MovementType::CONSUMPTION, '2026-10-02T08:00:00Z', 2);
        $this->seedMovement($beans, '0.125', MovementType::ADJUSTMENT, '2026-10-03T08:00:00Z', 3);
        $this->em()->flush();

        $client = $this->client();
        $client->request('GET', '/api/inventory');
        self::assertResponseIsSuccessful();

        /** @var array{items: list<array{material_id: string|int, name: string, stock_on_hand: string, below_threshold: bool}>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['items']);
        self::assertSame('7.625', $body['items'][0]['stock_on_hand']);
        self::assertTrue($body['items'][0]['below_threshold']);
    }

    public function testInventoryZeroForMaterialWithNoMovements(): void
    {
        $this->em()->persist(new Material('Cups', MaterialType::CONSUMABLE, BaseUnit::PIECE));
        $this->em()->flush();

        $client = $this->client();
        $client->request('GET', '/api/inventory');
        /** @var array{items: list<array{stock_on_hand: string}>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0.000', $body['items'][0]['stock_on_hand']);
    }

    public function testInventoryTypeAndBelowThresholdOnly(): void
    {
        $low = new Material('Saffron', MaterialType::RAW, BaseUnit::GRAM);
        $low->setReorderThreshold('5.000');
        $ok = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $this->em()->persist($low);
        $this->em()->persist($ok);
        $this->em()->flush(); // both 0.000 → saffron is below its threshold, beans has none

        $client = $this->client();
        $client->request('GET', '/api/inventory?belowThresholdOnly=true');
        /** @var array{items: list<array{name: string}>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['items']);
        self::assertSame('Saffron', $body['items'][0]['name']);
    }

    public function testInventoryOneMaterialWithLedgerSum(): void
    {
        $beans = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $this->em()->persist($beans);
        $this->seedMovement($beans, '12.345', MovementType::RECEIPT, '2026-10-05T08:00:00Z');
        $this->em()->flush();

        $client = $this->client();
        $client->request('GET', '/api/inventory/'.$beans->getId());
        /** @var array{stock_on_hand: string} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('12.345', $body['stock_on_hand']);
    }

    public function testMovementsNewestFirstAndTypeFilter(): void
    {
        $beans = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $this->em()->persist($beans);

        $this->seedMovement($beans, '10.000', MovementType::RECEIPT, '2026-10-01T08:00:00Z', 1);
        $this->seedMovement($beans, '-2.000', MovementType::CONSUMPTION, '2026-10-02T08:00:00Z', 2);
        $this->seedMovement($beans, '-1.000', MovementType::WASTE, '2026-10-03T08:00:00Z', 3);
        $this->em()->flush();
        $this->em()->clear();

        $client = $this->client();
        $client->request('GET', '/api/inventory/'.$beans->getId().'/movements');
        /** @var array{items: list<array{movement_type: string, signed_quantity: string}>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $body['items']);
        self::assertSame('WASTE', $body['items'][0]['movement_type']); // newest of three
        self::assertSame('-1.000', $body['items'][0]['signed_quantity']);

        $client->request('GET', '/api/inventory/'.$beans->getId().'/movements?movement_type=CONSUMPTION');
        /** @var array{items: list<array{movement_type: string}>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['items']);
        self::assertSame('CONSUMPTION', $body['items'][0]['movement_type']);
    }

    public function testMovementsDateRangeFilter(): void
    {
        $beans = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $this->em()->persist($beans);
        $this->seedMovement($beans, '10.000', MovementType::RECEIPT, '2026-10-01T08:00:00Z', 1);
        $this->seedMovement($beans, '-2.000', MovementType::CONSUMPTION, '2026-10-09T08:00:00Z', 2);
        $this->em()->flush();

        $client = $this->client();
        $client->request('GET', '/api/inventory/'.$beans->getId().'/movements?date_from=2026-10-05');
        /** @var array{items: list<array{movement_type: string}>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['items']);
        self::assertSame('CONSUMPTION', $body['items'][0]['movement_type']);
    }

    public function testMovementsUnknownMaterialIs404(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/inventory/999999/movements');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMovementsInvalidTypeIs422(): void
    {
        $beans = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $this->em()->persist($beans);
        $this->em()->flush();

        $client = $this->client();
        $client->request('GET', '/api/inventory/'.$beans->getId().'/movements?movement_type=THEFT');
        self::assertResponseStatusCodeSame(422);
    }
}
