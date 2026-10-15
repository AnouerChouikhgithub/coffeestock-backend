<?php

declare(strict_types=1);

namespace App\Tests\Controller;

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
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ReportApiTest extends WebTestCase
{
    use ResetDatabase;
    use \App\Tests\AuthenticatedTrait;

    private ?User $user = null;

    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $cachedClient = null;

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

    private function seedMovement(Material $m, string $qty, MovementType $type, string $at, int $docRef = 1): void
    {
        $this->em()->persist(new StockMovement($m, $type, $qty, new \DateTimeImmutable($at), SourceDocumentType::CONSUMPTION_ENTRY, $docRef, $this->user()));
    }

    // -------------------------------------------------------------------------
    // 25. GET /api/reports/inventory
    // -------------------------------------------------------------------------

    public function testInventoryReportMatchesLedgerAndIgnoresDateSnapshot(): void
    {
        $beans = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $beans->setReorderThreshold('10.000');
        $this->em()->persist($beans);
        $this->seedMovement($beans, '10.000', MovementType::RECEIPT, '2026-10-01T08:00:00Z');
        $this->seedMovement($beans, '-2.375', MovementType::CONSUMPTION, '2026-10-02T08:00:00Z');
        $this->em()->flush();

        $client = $this->client();
        $client->request('GET', '/api/reports/inventory?date=today');
        self::assertResponseIsSuccessful();

        /** @var array{page: int, perPage: int, total: int, items: list<array<string, mixed>>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['total']);
        self::assertSame('7.625', $body['items'][0]['stock_on_hand']);
        self::assertTrue($body['items'][0]['below_threshold']);
        self::assertSame('Beans', $body['items'][0]['name']);
    }

    public function testInventoryReportTypeFilterAndValidation(): void
    {
        $beans = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $cups = new Material('Cups', MaterialType::CONSUMABLE, BaseUnit::PIECE);
        $this->em()->persist($beans);
        $this->em()->persist($cups);
        $this->em()->flush();

        $client = $this->client();
        $client->request('GET', '/api/reports/inventory?type=CONSUMABLE');
        /** @var array{items: list<array{name: string}>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['items']);
        self::assertSame('Cups', $body['items'][0]['name']);

        $client->request('GET', '/api/reports/inventory?type=LIQUID');
        self::assertResponseStatusCodeSame(422);

        $client->request('GET', '/api/reports/inventory?date=2026-10-01');
        self::assertResponseStatusCodeSame(422);
    }

    // -------------------------------------------------------------------------
    // 26. GET /api/reports/movements
    // -------------------------------------------------------------------------

    public function testMovementsReportAggregatesPerMaterialAndType(): void
    {
        $beans = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $this->em()->persist($beans);
        $this->seedMovement($beans, '10.000', MovementType::RECEIPT, '2026-10-01T08:00:00Z');
        $this->seedMovement($beans, '-2.500', MovementType::CONSUMPTION, '2026-10-02T08:00:00Z');
        $this->seedMovement($beans, '-0.250', MovementType::CONSUMPTION, '2026-10-03T08:00:00Z');
        $this->seedMovement($beans, '-1.000', MovementType::WASTE, '2026-10-09T08:00:00Z');
        $this->em()->flush();
        $this->em()->clear();

        $client = $this->client();

        // Full range
        $client->request('GET', '/api/reports/movements?material_id='.$beans->getId());
        self::assertResponseIsSuccessful();
        /** @var array{total: int, items: list<array<string, mixed>>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(3, $body['total']);
        // ordered by material_id then movement_type (alphabetic): CONSUMPTION, RECEIPT, WASTE
        self::assertSame('CONSUMPTION', $body['items'][0]['movement_type']);
        self::assertSame('-2.750', $body['items'][0]['total_quantity']);
        self::assertSame(2, $body['items'][0]['movement_count']);
        self::assertSame('10.000', $body['items'][1]['total_quantity']);

        // Date-ranged: only the waste lands inside
        $client->request('GET', '/api/reports/movements?material_id='.$beans->getId().'&date_from=2026-10-04&date_to=2026-10-10');
        /** @var array{items: list<array<string, mixed>>} $ranged */
        $ranged = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $ranged['items']);
        self::assertSame('WASTE', $ranged['items'][0]['movement_type']);
        self::assertSame('-1.000', $ranged['items'][0]['total_quantity']);

        // Type filter
        $client->request('GET', '/api/reports/movements?material_id='.$beans->getId().'&movement_type=RECEIPT');
        /** @var array{items: list<array<string, mixed>>} $typed */
        $typed = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $typed['items']);
        self::assertSame('RECEIPT', $typed['items'][0]['movement_type']);
    }

    public function testMovementsReportValidation(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/reports/movements?material_id=abc');
        self::assertResponseStatusCodeSame(422);

        $client->request('GET', '/api/reports/movements?movement_type=THEFT');
        self::assertResponseStatusCodeSame(422);

        $client->request('GET', '/api/reports/movements?date_from=nope');
        self::assertResponseStatusCodeSame(422);
    }

    // -------------------------------------------------------------------------
    // 27/28. CSV exports
    // -------------------------------------------------------------------------

    public function testInventoryCsvMatchesJsonReport(): void
    {
        $beans = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $beans->setReorderThreshold('10.000');
        $this->em()->persist($beans);
        $this->seedMovement($beans, '7.625', MovementType::RECEIPT, '2026-10-01T08:00:00Z');
        $this->em()->flush();

        $client = $this->client();
        $client->request('GET', '/api/reports/inventory/export.csv');
        self::assertResponseIsSuccessful();
        self::assertSame('text/csv; charset=utf-8', $client->getResponse()->headers->get('Content-Type'));

        $csv = $client->getResponse()->getContent() ?: '';
        self::assertStringStartsWith("material_id,name,type,base_unit,stock_on_hand,below_threshold\n", $csv);
        self::assertMatchesRegularExpression('/\n[0-9]+,Beans,RAW,g,7\.625,true\n$/', $csv);
    }

    public function testMovementsCsvMatchesReport(): void
    {
        $beans = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $this->em()->persist($beans);
        $this->seedMovement($beans, '10.000', MovementType::RECEIPT, '2026-10-01T08:00:00Z');
        $this->em()->flush();
        $this->em()->clear();

        $client = $this->client();
        $client->request('GET', '/api/reports/movements/export.csv?material_id='.$beans->getId());
        self::assertResponseIsSuccessful();

        $csv = $client->getResponse()->getContent() ?: '';
        self::assertStringStartsWith("material_id,movement_type,total_quantity,movement_count\n", $csv);
        self::assertMatchesRegularExpression('/\n[0-9]+,RECEIPT,10\.000,1\n$/', $csv);
    }

    public function testCsvRejectsInvalidFiltersWithJsonError(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/reports/inventory/export.csv?type=LIQUID');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));

        $client->request('GET', '/api/reports/movements/export.csv?movement_type=THEFT');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
    }

    // -------------------------------------------------------------------------
    // 29. GET /api/audit-logs
    // -------------------------------------------------------------------------

    public function testAuditLogsListRowsAndValidateFilters(): void
    {
        $this->em()->persist(new AuditLog($this->user(), AuditAction::CONSUMPTION_CREATE, 'consumption_entry', 55, AuditOutcome::SUCCESS, null, new \DateTimeImmutable('2026-10-02T08:00:00Z')));
        $this->em()->flush();

        $client = $this->client();
        $client->request('GET', '/api/audit-logs');
        self::assertResponseIsSuccessful();
        /** @var array{total: int, items: list<array<string, mixed>>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['total']);
        self::assertSame('CONSUMPTION_CREATE', $body['items'][0]['action']);
        self::assertSame(55, $body['items'][0]['target_entity_id']);
        self::assertSame('SUCCESS', $body['items'][0]['outcome']);

        // action filter
        $client->request('GET', '/api/audit-logs?action=WASTE_CREATE');
        /** @var array{total: int} $empty */
        $empty = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $empty['total']);

        // validity
        $client->request('GET', '/api/audit-logs?action=HACKING');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/api/audit-logs?actor_user_id=x');
        self::assertResponseStatusCodeSame(422);
    }
}
