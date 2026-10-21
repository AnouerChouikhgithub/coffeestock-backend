<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Material;
use App\Entity\Session;
use App\Entity\StockMovement;
use App\Entity\Supplier;
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
 * Phase 9 authorization boundary (REQ-080/REQ-081).
 *
 * Every other functional test in this suite authenticates as MANAGER through
 * AuthenticatedTrait, so collectively they showed that the firewall can let a
 * caller *in* — never that it keeps anyone *out*. This file is the negative
 * half: it asserts the 401 and 403 branches, and pairs each denial with a
 * positive control so a blanket "deny everything" regression cannot pass.
 *
 * One client per test method: WebTestCase::createClient() may only boot the
 * kernel once, so tests that need two roles are split rather than merged.
 */
final class AuthorizationApiTest extends WebTestCase
{
    use ResetDatabase;
    use \App\Tests\AuthenticatedTrait;

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function seedMaterial(KernelBrowser $client, string $name = 'Beans'): Material
    {
        $em = $this->em($client);
        $material = new Material($name, MaterialType::RAW, BaseUnit::GRAM);
        $em->persist($material);
        $em->flush();

        return $material;
    }

    /** Give a material a ledger balance so a consumption can legitimately succeed. */
    private function seedStock(KernelBrowser $client, Material $material, string $quantity): void
    {
        $em = $this->em($client);
        $user = new User('seed-'.bin2hex(random_bytes(4)), UserRole::OPERATOR);
        $em->persist($user);
        $em->persist(new StockMovement(
            $material,
            MovementType::RECEIPT,
            $quantity,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            SourceDocumentType::RECEIPT_ITEM,
            0,
            $user,
        ));
        $em->flush();
    }

    // -------------------------------------------------------------------------
    // 401 — missing / malformed credentials
    // -------------------------------------------------------------------------

    public function testAnonymousRequestsAreRejectedWithTheContractPayload(): void
    {
        $client = self::createClient();

        foreach ([
            ['GET', '/api/materials'],
            ['GET', '/api/inventory'],
            ['GET', '/api/reports/inventory'],
            ['GET', '/api/reports/movements/export.csv'],
            ['GET', '/api/audit-logs'],
            ['POST', '/api/consumptions'],
        ] as [$method, $uri]) {
            $client->request($method, $uri);
            self::assertResponseStatusCodeSame(401, sprintf('%s %s must require authentication', $method, $uri));
        }

        // Uniform error payload per docs/api-contract.md (REQ-080).
        $client->request('GET', '/api/materials');
        $body = $this->json($client);
        self::assertSame('AUTH_REQUIRED', $body['error']['code']);
        self::assertSame([], $body['error']['details']);
    }

    public function testHealthEndpointStaysPublic(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
    }

    public function testMalformedAndUnknownBearerTokensAreRejected(): void
    {
        $client = self::createClient();

        // Not a bearer credential at all — must not fall through as anonymous success.
        $client->setServerParameter('HTTP_Authorization', 'not-a-bearer-token');
        $client->request('GET', '/api/materials');
        self::assertResponseStatusCodeSame(401);

        // Well-formed but unknown: the hash is absent from the session table.
        $client->setServerParameter('HTTP_Authorization', 'Bearer '.str_repeat('0', 64));
        $client->request('GET', '/api/materials');
        self::assertResponseStatusCodeSame(401);

        // Empty bearer value.
        $client->setServerParameter('HTTP_Authorization', 'Bearer ');
        $client->request('GET', '/api/materials');
        self::assertResponseStatusCodeSame(401);
    }

    public function testExpiredSessionsAndInactiveUsersAreRejected(): void
    {
        $client = self::createClient();
        $em = $this->em($client);
        $past = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 hour');
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 hour');

        // Valid hash, but the session has already lapsed.
        $lapsedUser = new User('lapsed-'.bin2hex(random_bytes(4)), UserRole::MANAGER);
        $expiredToken = bin2hex(random_bytes(32));
        $em->persist($lapsedUser);
        $em->persist(new Session($lapsedUser, hash('sha256', $expiredToken), $past));
        $em->flush();

        $client->setServerParameter('HTTP_Authorization', 'Bearer '.$expiredToken);
        $client->request('GET', '/api/materials');
        self::assertResponseStatusCodeSame(401, 'an expired session must not authenticate');

        // Session still current, but the principal has been deactivated.
        $inactive = new User('inactive-'.bin2hex(random_bytes(4)), UserRole::MANAGER);
        $inactive->setIsActive(false);
        $inactiveToken = bin2hex(random_bytes(32));
        $em->persist($inactive);
        $em->persist(new Session($inactive, hash('sha256', $inactiveToken), $future));
        $em->flush();

        $client->setServerParameter('HTTP_Authorization', 'Bearer '.$inactiveToken);
        $client->request('GET', '/api/materials');
        self::assertResponseStatusCodeSame(401, 'a deactivated user must not authenticate');
    }

    // -------------------------------------------------------------------------
    // 403 — authenticated but not authorized
    // -------------------------------------------------------------------------

    public function testOperatorCannotPerformManagerOnlyWrites(): void
    {
        $client = $this->authClient('OPERATOR');
        $material = $this->seedMaterial($client);

        // Each payload is intentionally well-formed: the request must be refused by
        // the authorization layer, not incidentally by validation.
        $managerOnly = [
            ['POST', '/api/materials', ['name' => 'Operator Beans', 'type' => 'RAW', 'base_unit' => 'g']],
            ['POST', '/api/suppliers', ['name' => 'Operator Co']],
            ['POST', '/api/receipts', ['supplier_id' => 1, 'delivery_date' => '2026-10-01', 'items' => []]],
            ['POST', '/api/adjustments', ['material_id' => $material->getId(), 'quantity_delta' => '1.000', 'reason' => 'op']],
            ['GET', '/api/audit-logs', null],
        ];

        foreach ($managerOnly as [$method, $uri, $payload]) {
            $client->request(
                $method,
                $uri,
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                null === $payload ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(403, sprintf('OPERATOR must not be allowed to %s %s', $method, $uri));
        }

        // The contract splits stocktaking: OPERATOR may open a DRAFT, but only a
        // MANAGER may commit variances into the ledger.
        $client->request('POST', '/api/stocktakes', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'counts' => [['material_id' => $material->getId(), 'counted_quantity' => '1.000']],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, 'OPERATOR must be able to open a DRAFT stocktake');

        $stocktakeId = $this->json($client)['id'];
        $client->request('POST', "/api/stocktakes/{$stocktakeId}/commit", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403, 'OPERATOR must not be able to commit a stocktake');
    }

    /**
     * The contract grants supplier deactivation to MANAGER (it is a soft, reversible
     * flag flip), so an OPERATOR must be the role that is refused here.
     */
    public function testOperatorCannotDeactivateSupplier(): void
    {
        $client = $this->authClient('OPERATOR');
        $em = $this->em($client);
        $supplier = new Supplier('Manager Only Co');
        $em->persist($supplier);
        $em->flush();
        $id = $supplier->getId();

        $client->request('POST', "/api/suppliers/{$id}/deactivate");

        self::assertResponseStatusCodeSame(403);
        // The contract requires every error body to use the uniform payload shape.
        self::assertSame('FORBIDDEN', $this->json($client)['error']['code']);

        // A refused request must not have changed any state.
        $em->clear();
        $reloaded = $em->find(Supplier::class, $id);
        self::assertInstanceOf(Supplier::class, $reloaded);
        self::assertTrue($reloaded->isActive(), 'a 403 must leave the supplier untouched');
    }

    public function testManagerCanDeactivateSupplier(): void
    {
        $client = $this->authClient('MANAGER');
        $em = $this->em($client);
        $supplier = new Supplier('Manager Deactivatable Co');
        $em->persist($supplier);
        $em->flush();
        $id = $supplier->getId();

        $client->request('POST', "/api/suppliers/{$id}/deactivate");

        self::assertResponseStatusCodeSame(204);

        $em->clear();
        $reloaded = $em->find(Supplier::class, $id);
        self::assertInstanceOf(Supplier::class, $reloaded);
        self::assertFalse($reloaded->isActive());
    }

    // -------------------------------------------------------------------------
    // Audit trail — Admin only per the contract matrix (REQ-110)
    // -------------------------------------------------------------------------

    public function testManagerCannotReadAuditLogs(): void
    {
        $client = $this->authClient('MANAGER');

        $client->request('GET', '/api/audit-logs');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('FORBIDDEN', $this->json($client)['error']['code']);
    }

    public function testAdminCanReadAuditLogs(): void
    {
        $client = $this->authClient('ADMIN');

        $client->request('GET', '/api/audit-logs');

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('items', $this->json($client));
    }

    /**
     * Regression: User::getRoles() returns a single ROLE_* string, so before the
     * role hierarchy existed an ADMIN was refused every `roles: ROLE_MANAGER` rule
     * — i.e. the most privileged account could not create a material.
     */
    public function testAdminInheritsManagerWrites(): void
    {
        $client = $this->authClient('ADMIN');

        $client->request('POST', '/api/materials', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Admin Beans',
            'type' => 'RAW',
            'base_unit' => 'g',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201, 'ADMIN must inherit MANAGER permissions');
    }

    /**
     * Errors raised by the framework itself (a firewall denial above, an unmatched
     * route or a rejected HTTP method here) never reach a controller, so without
     * ApiErrorSubscriber they return Symfony's HTML error page to a JSON client.
     */
    public function testFrameworkGeneratedErrorsUseTheContractPayload(): void
    {
        $client = $this->authClient('MANAGER');

        $client->request('GET', '/api/does-not-exist');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('NOT_FOUND', $this->json($client)['error']['code']);

        $client->request('DELETE', '/api/materials');
        self::assertResponseStatusCodeSame(405);
        self::assertSame('METHOD_NOT_ALLOWED', $this->json($client)['error']['code']);
    }

    // -------------------------------------------------------------------------
    // Positive controls — the denials above must be authorization, not blanket refusal
    // -------------------------------------------------------------------------

    public function testOperatorCanRecordConsumption(): void
    {
        $client = $this->authClient('OPERATOR');
        $material = $this->seedMaterial($client, 'Operator Consumable');
        $this->seedStock($client, $material, '10.000');

        $client->request('POST', '/api/consumptions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'material_id' => $material->getId(),
            'quantity' => '1.000',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
    }

    public function testManagerCanCreateMaterial(): void
    {
        $client = $this->authClient('MANAGER');

        $client->request('POST', '/api/materials', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Manager Beans',
            'type' => 'RAW',
            'base_unit' => 'g',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
    }

    /**
     * ADMIN reaches deactivation through the role hierarchy. The pre-existing
     * SupplierApiTest worked around the endpoint by flipping the flag straight in
     * the database, so this is its only end-to-end coverage.
     */
    public function testAdminCanDeactivateSupplier(): void
    {
        $client = $this->authClient('ADMIN');
        $em = $this->em($client);
        $supplier = new Supplier('Fading Co');
        $em->persist($supplier);
        $em->flush();
        $id = $supplier->getId();

        $client->request('POST', "/api/suppliers/{$id}/deactivate");
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', "/api/suppliers/{$id}");
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json($client)['is_active'], 'deactivation must be soft, not a delete');
    }
}
