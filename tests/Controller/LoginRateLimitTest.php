<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Brute-force protection on POST /api/login (REQ-081).
 *
 * Every other test in this suite signs in successfully or not at all, so the
 * throttling path had no coverage: an attacker could guess passwords for eight
 * hours' worth of attempts at full speed. These tests pin the threshold, the
 * rejection payload, the recovery path and the non-enumeration property.
 */
final class LoginRateLimitTest extends WebTestCase
{
    use ResetDatabase;

    private const PASSWORD = 'correct-horse';

    /** The limiter pool is filesystem-backed and outlives a single test case. */
    private function clearLimiterState(KernelBrowser $client): void
    {
        $client->getContainer()->get('cache.rate_limiter')->clear();
    }

    private function seedUser(KernelBrowser $client, string $username): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User($username, UserRole::MANAGER);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();
    }

    private function attempt(
        KernelBrowser $client,
        string $username,
        string $password = 'wrong-password',
    ): int {
        $client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'username' => $username,
            'password' => $password,
        ], JSON_THROW_ON_ERROR));

        return $client->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function body(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testSixthAttemptWithinTheWindowIsThrottled(): void
    {
        $client = self::createClient();
        $this->clearLimiterState($client);
        $this->seedUser($client, 'rate_limited_manager');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertSame(401, $this->attempt($client, 'rate_limited_manager'), "attempt {$attempt} must be a plain credential rejection");
        }

        self::assertSame(429, $this->attempt($client, 'rate_limited_manager'));

        $body = $this->body($client);
        self::assertSame('RATE_LIMITED', $body['error']['code']);
        self::assertSame([['field' => 'retry_after', 'issue' => $body['error']['details'][0]['issue']]], $body['error']['details']);

        $retryAfter = (int) $client->getResponse()->headers->get('Retry-After');
        self::assertGreaterThan(0, $retryAfter, 'the client needs a usable cooldown hint');

        // A correct password does not bypass an active throttle.
        self::assertSame(429, $this->attempt($client, 'rate_limited_manager', self::PASSWORD));
    }

    public function testUsernameCasingAndWhitespaceShareOneBucket(): void
    {
        $client = self::createClient();
        $this->clearLimiterState($client);
        $this->seedUser($client, 'casing_manager');

        // Five attempts spread across spellings that all name the same account.
        $variants = ['Casing_Manager', 'CASING_MANAGER', 'casing_manager ', '  casing_manager', 'casing_manager'];
        foreach ($variants as $variant) {
            self::assertSame(401, $this->attempt($client, $variant));
        }

        self::assertSame(
            429,
            $this->attempt($client, 'casing_manager'),
            'casing/whitespace variants must not reset the counter',
        );
    }

    public function testThrottleDoesNotRevealWhetherTheAccountExists(): void
    {
        $client = self::createClient();
        $this->clearLimiterState($client);
        $this->seedUser($client, 'existing_manager');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->attempt($client, 'existing_manager');
        }
        self::assertSame(429, $this->attempt($client, 'existing_manager'));
        $existing = $this->body($client);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->attempt($client, 'ghost_account_'.bin2hex(random_bytes(3)));
        }

        $ghostUsername = 'ghost_account_probe';
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->attempt($client, $ghostUsername);
        }
        self::assertSame(429, $this->attempt($client, $ghostUsername));
        $ghost = $this->body($client);

        self::assertSame($existing['error']['code'], $ghost['error']['code']);
        self::assertSame(
            preg_replace('/\d+/', 'N', $existing['error']['message']),
            preg_replace('/\d+/', 'N', $ghost['error']['message']),
            'the throttled response must not distinguish an existing account from an absent one',
        );
    }

    public function testSuccessfulLoginClearsThePenalty(): void
    {
        $client = self::createClient();
        $this->clearLimiterState($client);
        $this->seedUser($client, 'typo_manager');

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            self::assertSame(401, $this->attempt($client, 'typo_manager'));
        }

        // The real user finally gets it right: the four typos must not shadow them.
        self::assertSame(200, $this->attempt($client, 'typo_manager', self::PASSWORD));

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            self::assertSame(401, $this->attempt($client, 'typo_manager'), 'the counter must have been reset by the successful sign-in');
        }
    }

    public function testIpLimiterStopsUsernameSpraying(): void
    {
        $client = self::createClient();
        $this->clearLimiterState($client);

        // Each username gets its own account bucket, so only the IP limiter can
        // stop a spray from one host. The limit is 50 (config/packages/rate_limiter.yaml).
        for ($attempt = 1; $attempt <= 50; ++$attempt) {
            self::assertSame(401, $this->attempt($client, 'spray_'.$attempt), "spray attempt {$attempt}");
        }

        self::assertSame(429, $this->attempt($client, 'spray_overflow'));
        self::assertSame('RATE_LIMITED', $this->body($client)['error']['code']);
    }
}
