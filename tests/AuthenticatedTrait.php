<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase 9 test plumbing: seeds a hashed-password user for the MANAGER role,
 * performs POST /api/login and returns a client whose every request carries the
 * `Authorization: Bearer` header. Cached per role within a single test because
 * Foundry's ResetDatabase wipes the session/user tables between tests.
 */
trait AuthenticatedTrait
{
    /** @var array<string, KernelBrowser> */
    private array $authClients = [];

    private function authClient(string $role = 'MANAGER'): KernelBrowser
    {
        if (isset($this->authClients[$role])) {
            return $this->authClients[$role];
        }

        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User('auth_'.strtolower($role).'_'.bin2hex(random_bytes(4)), UserRole::from($role));
        $user->setPassword($hasher->hashPassword($user, 'correct-horse'));
        $em->persist($user);
        $em->flush();

        $client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'username' => $user->getUsername(),
            'password' => 'correct-horse',
        ], JSON_THROW_ON_ERROR));

        $status = $client->getResponse()->getStatusCode();
        if (200 !== $status) {
            self::fail(sprintf('Test login for role %s failed with HTTP %d.', $role, $status));
        }

        /** @var array{token: string} $body */
        $body = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        $client->setServerParameter('HTTP_Authorization', 'Bearer '.$body['token']);

        return $this->authClients[$role] = $client;
    }
}
