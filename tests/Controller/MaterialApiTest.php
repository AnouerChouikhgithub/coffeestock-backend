<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Material;
use App\Enum\BaseUnit;
use App\Enum\MaterialType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class MaterialApiTest extends WebTestCase
{
    use ResetDatabase;

    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $cachedClient = null;

    /**
     * Ensures the test kernel boots through createClient exactly once per test,
     * before any other kernel-booting access (e.g. the entity manager).
     */
    private function client(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        return $this->cachedClient ??= self::createClient();
    }

    private function em(): EntityManagerInterface
    {
        return $this->client()->getContainer()->get(EntityManagerInterface::class);
    }

    public function testUnitsListReturnsExactFive(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/units');

        self::assertResponseIsSuccessful();
        /** @var array{items: list<string>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['g', 'kg', 'ml', 'l', 'piece'], $body['items']);
    }

    public function testCreateMaterialHappyPath(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/materials', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Espresso beans',
            'type' => 'RAW',
            'base_unit' => 'g',
            'reorder_threshold' => '500.000',
            'stocktake_threshold' => null,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        /** @var array<string, mixed> $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Espresso beans', $body['name']);
        self::assertSame('RAW', $body['type']);
        self::assertSame('g', $body['base_unit']);
        self::assertSame('500.000', $body['reorder_threshold']);
        self::assertNull($body['stocktake_threshold']);
        self::assertTrue($body['is_active']);
    }

    public function testCreateMaterialDuplicateNameGivesDistributedCode(): void
    {
        // Seed one directly (client created first so kernel boots exactly once)
        $client = $this->client();
        $this->em()->persist(new Material('Milk', MaterialType::RAW, BaseUnit::MILLILITRE));
        $this->em()->flush();

        $client = $this->client();
        $client->request('POST', '/api/materials', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Milk',
            'type' => 'RAW',
            'base_unit' => 'ml',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        /** @var array{error: array{code: string}} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('DUPLICATE_NAME', $body['error']['code']);
    }

    public function testCreateMaterialInvalidUnitRejected(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/materials', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Beans',
            'type' => 'RAW',
            'base_unit' => 'bushel', // not in the list
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        /** @var array{error: array{code: string, details: list<array{field: string, issue: string}>}} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
        self::assertStringContainsString('piece', $body['error']['details'][0]['issue']); // allowed set revealed
    }

    public function testCreateMaterialTooManyDecimalsRejected(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/materials', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Beans',
            'type' => 'RAW',
            'base_unit' => 'g',
            'reorder_threshold' => '1.2345', // 4 d.p. — violates scale 3
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testReadMaterial404(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/materials/999999');

        self::assertResponseStatusCodeSame(404);
        /** @var array{error: array{code: string}} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('NOT_FOUND', $body['error']['code']);
    }

    public function testPatchRejectsBaseUnitChange(): void
    {
        $client = $this->client();
        $material = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $this->em()->persist($material);
        $this->em()->flush();

        $client->request('PATCH', '/api/materials/'.$material->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'base_unit' => 'kg', // immutable — must reject
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        /** @var array{error: array{details: list<array{field: string}>}} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('base_unit', $body['error']['details'][0]['field']);
    }

    public function testPatchRenameAndDeactivateWorks(): void
    {
        $client = $this->client();
        $material = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM);
        $this->em()->persist($material);
        $this->em()->flush();
        $id = $material->getId();

        $client->request('PATCH', '/api/materials/'.$id, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Arabica beans',
            'is_active' => false,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Arabica beans', $body['name']);
        self::assertFalse($body['is_active']);

        // default list (includeInactive=false) must exclude it
        $client->request('GET', '/api/materials');
        /** @var array{items: list<array<string, mixed>>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['items']);
    }

    public function testListTypeAndSearchFilters(): void
    {
        $this->em()->persist($b = new Material('Beans', MaterialType::RAW, BaseUnit::GRAM));
        $this->em()->persist(new Material('Cups', MaterialType::CONSUMABLE, BaseUnit::PIECE));
        $this->em()->flush();

        $client = $this->client();
        $client->request('GET', '/api/materials?type=RAW');
        /** @var array{items: list<array{name: string}>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['items']);
        self::assertSame('Beans', $body['items'][0]['name']);

        $client->request('GET', '/api/materials?search=cup');
        /** @var array{items: list<array{name: string}>} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['items']);
        self::assertSame('Cups', $body['items'][0]['name']);
    }

    public function testMalformedJsonBodyOnCreate(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/materials', [], [], ['CONTENT_TYPE' => 'application/json'], '{not-json');

        self::assertResponseStatusCodeSame(400);
    }
}
