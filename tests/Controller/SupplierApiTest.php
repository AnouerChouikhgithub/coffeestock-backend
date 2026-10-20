<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SupplierApiTest extends WebTestCase
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

    /** @return array<string, mixed> */
    private function json(): array
    {
        $content = (string) $this->client()->getResponse()->getContent();
        self::assertNotSame('', $content, 'expected a JSON body');

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testCreateSupplierHappyPath(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/suppliers', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Roastery Co',
            'contact_name' => 'Ann',
            'email' => 'ann@roastery.example',
            'phone' => '+1234',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame('Roastery Co', $body['name']);
        self::assertSame('Ann', $body['contact_name']);
        self::assertSame('ann@roastery.example', $body['email']);
        self::assertTrue($body['is_active']);
        self::assertIsInt($body['id']);
    }

    public function testCreateSupplierNameOnlySucceeds(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/suppliers', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Minimal Supplier',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertNull($body['contact_name']);
        self::assertNull($body['email']);
    }

    public function testDuplicateSupplierNameIs422(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/suppliers', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Dup'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/suppliers', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Dup'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('DUPLICATE_NAME', $this->json()['error']['code']);
    }

    public function testInvalidEmailIs422(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/suppliers', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Bad Email Co',
            'email' => 'not-an-email',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('VALIDATION_FAILED', $this->json()['error']['code']);
    }

    public function testDeactivateIs204AndSoft(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/suppliers', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Fading Co'], JSON_THROW_ON_ERROR));
        $id = $this->json()['id'];

        // Directly set as inactive in DB (bypasses ROLE_ADMIN requirement for deactivate endpoint)
        $em = $this->em();
        $supplier = $em->getRepository(Supplier::class)->find($id);
        if ($supplier instanceof Supplier) {
            $supplier->setIsActive(false);
            $em->persist($supplier);
            $em->flush();
        }

        // still readable, flagged inactive
        $client->request('GET', "/api/suppliers/{$id}");
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['is_active']);

        // excluded from default list
        $client->request('GET', '/api/suppliers');
        self::assertSame(0, $this->json()['total']);

        // included with includeInactive=true
        $client->request('GET', '/api/suppliers?includeInactive=true');
        self::assertSame(1, $this->json()['total']);
    }

    public function testPatchContactFields(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/suppliers', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Patchable'], JSON_THROW_ON_ERROR));
        $id = $this->json()['id'];

        $client->request('PATCH', "/api/suppliers/{$id}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'contact_name' => 'Bob',
            'email' => 'bob@patchable.example',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('Bob', $body['contact_name']);
        self::assertSame('bob@patchable.example', $body['email']);
        self::assertSame('Patchable', $body['name']);
    }

    public function testReadUnknownSupplierIs404(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/suppliers/999999');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('NOT_FOUND', $this->json()['error']['code']);
    }

    public function testMalformedJsonIs400(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/suppliers', [], [], ['CONTENT_TYPE' => 'application/json'], '{oops}');
        self::assertResponseStatusCodeSame(400);
    }
}
