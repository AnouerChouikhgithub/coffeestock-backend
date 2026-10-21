<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Material;
use App\Entity\Supplier;
use App\Entity\User;
use App\Enum\BaseUnit;
use App\Enum\MaterialType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * REQ-030 allows a delivery dated today or earlier.
 *
 * Regression: `DateTimeImmutable::createFromFormat('Y-m-d', ...)` keeps the
 * current time-of-day, so "today" parsed as today 14:37 and compared greater than
 * today 00:00. Every receipt dated today was rejected as "must not be in the
 * future" — i.e. the single most common case was impossible, while the UI hint
 * told the user today was allowed.
 */
final class ReceiptDateBoundaryTest extends WebTestCase
{
    use ResetDatabase;
    use \App\Tests\AuthenticatedTrait;

    /** @return array{0: int, 1: int} */
    private function seed(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $supplier = new Supplier('Boundary Supplier');
        $material = new Material('Boundary Beans', MaterialType::RAW, BaseUnit::GRAM);
        $em->persist($supplier);
        $em->persist($material);
        $em->flush();

        $supplierId = $supplier->getId();
        $materialId = $material->getId();
        // Doctrine assigns identity on flush; a null id here means the fixture
        // never reached the database.
        if (null === $supplierId || null === $materialId) {
            self::fail('The seeded supplier and material must have ids after flush.');
        }

        return [$supplierId, $materialId];
    }

    private function post(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $date, int $supplierId, int $materialId): void
    {
        $client->request('POST', '/api/receipts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'supplier_id' => $supplierId,
            'delivery_date' => $date,
            'items' => [[
                'material_id' => $materialId,
                'quantity' => '1.000',
                'batch' => ['batch_number' => 'B-'.bin2hex(random_bytes(3))],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    public function testDeliveryDatedTodayIsAccepted(): void
    {
        $client = $this->authClient('MANAGER');
        [$supplierId, $materialId] = $this->seed($client);

        $this->post($client, (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d'), $supplierId, $materialId);

        self::assertResponseStatusCodeSame(201);
    }

    public function testDeliveryDatedTomorrowIsRejected(): void
    {
        $client = $this->authClient('MANAGER');
        [$supplierId, $materialId] = $this->seed($client);

        $tomorrow = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $this->post($client, $tomorrow, $supplierId, $materialId);

        self::assertResponseStatusCodeSame(422);
        /** @var array{error: array{details: list<array{field: string, issue: string}>}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('delivery_date', $body['error']['details'][0]['field']);
    }

    public function testDateFromFilterIncludesReceiptsDatedToday(): void
    {
        $client = $this->authClient('MANAGER');
        [$supplierId, $materialId] = $this->seed($client);

        $today = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d');
        $this->post($client, $today, $supplierId, $materialId);
        self::assertResponseStatusCodeSame(201);

        $client->request('GET', '/api/receipts?date_from='.$today);

        self::assertResponseIsSuccessful();
        /** @var array{total: int} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['total'], 'a receipt dated today must satisfy date_from=today');
    }
}
