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
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Phase-5 concurrency requirement: two simultaneous consumptions must not
 * overdraw stock. UsageService takes SELECT ... FOR UPDATE on the material
 * row before reading the balance; this test holds that lock from a second
 * connection (simulating an in-flight concurrent writer) and asserts the API
 * writer cannot proceed past it — it fails deterministically instead of
 * silently overdrawing.
 */
final class StockConcurrencyTest extends WebTestCase
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

    public function testConcurrentConsumptionCannotOverdraw(): void
    {
        $client = $this->client();
        $em = $this->em();

        $material = new Material('Beans-race-'.uniqid(), MaterialType::CONSUMABLE, BaseUnit::GRAM);
        $em->persist($material);
        $user = new User('seed-race', UserRole::OPERATOR);
        $em->persist($user);
        $em->flush();
        $em->persist(new StockMovement(
            $material,
            MovementType::RECEIPT,
            '10.000',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            SourceDocumentType::RECEIPT_ITEM,
            0,
            $user,
        ));
        $em->flush();

        // Second, independent DB connection holding the material row lock.
        $params = $em->getConnection()->getParams();
        $rival = DriverManager::getConnection($params);
        $rival->beginTransaction();
        $rival->executeQuery('SELECT id FROM material WHERE id = :id FOR UPDATE', ['id' => $material->getId()]);

        try {
            // Bound the API writer's lock wait so the test cannot hang.
            $em->getConnection()->executeStatement("SET lock_timeout = '500ms'");

            $client->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'material_id' => $material->getId(),
                'quantity' => '6.000',
            ], JSON_THROW_ON_ERROR));

            // While the rival holds the lock the writer must not land a movement.
            self::assertSame(
                0,
                (int) $em->getConnection()->fetchOne(
                    'SELECT COUNT(*) FROM stock_movement WHERE material_id = :m AND movement_type = :t',
                    ['m' => $material->getId(), 't' => 'CONSUMPTION'],
                ),
                'no consumption may commit while another writer holds the material lock',
            );

            $em->getConnection()->executeStatement('SET lock_timeout = 0');
        } finally {
            $rival->rollBack();
            $rival->close();
        }

        // Lock released → the same consumption now succeeds exactly once.
        $client->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $material->getId(),
            'quantity' => '6.000',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent());

        $client->request('GET', '/api/inventory/'.$material->getId());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('4.000', $body['stock_on_hand']);

        // A second concurrent-style draw that would floor-cross is still blocked.
        $client->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $material->getId(),
            'quantity' => '5.000',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }
}
