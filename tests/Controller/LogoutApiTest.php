<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Session;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * POST /api/logout — server-side revocation.
 *
 * The backend issues 8-hour tokens; without revocation a signed-out user's token
 * stays valid for the rest of that window, so "sign out" would only be a UI
 * gesture. These tests assert the session row is actually destroyed.
 */
final class LogoutApiTest extends WebTestCase
{
    use ResetDatabase;

    /** @return array{client: \Symfony\Bundle\FrameworkBundle\KernelBrowser, token: string} */
    private function signIn(): array
    {
        $client = self::createClient();

        $client->getContainer()->get('cache.rate_limiter')->clear();

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User('logout_'.bin2hex(random_bytes(4)), UserRole::MANAGER);
        $user->setPassword($hasher->hashPassword($user, 'correct-horse'));
        $em->persist($user);
        $em->flush();

        $client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'username' => $user->getUsername(),
            'password' => 'correct-horse',
        ], JSON_THROW_ON_ERROR));

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return ['client' => $client, 'token' => (string) $body['token']];
    }

    public function testLogoutRevokesTheTokenAndDeletesTheSessionRow(): void
    {
        ['client' => $client, 'token' => $token] = $this->signIn();

        $client->setServerParameter('HTTP_Authorization', 'Bearer '.$token);
        $client->request('GET', '/api/materials');
        self::assertResponseIsSuccessful('the fresh token works');

        $client->request('POST', '/api/logout');
        self::assertResponseStatusCodeSame(204);

        // The same token must now be worthless.
        $client->request('GET', '/api/materials');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('AUTH_REQUIRED', json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull(
            $em->getRepository(Session::class)->findByTokenHash(hash('sha256', $token)),
            'the session row must be deleted, not merely ignored',
        );
    }

    public function testLogoutRequiresAuthentication(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/logout');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('AUTH_REQUIRED', json_decode((string) $client->getResponse()->getContent(), true)['error']['code']);
    }
}
