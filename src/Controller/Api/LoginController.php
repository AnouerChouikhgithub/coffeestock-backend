<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Session;
use App\Entity\User;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/login (contract endpoint 2, REQ-081, OD-8 option A).
 * Issues an opaque bearer token backed by a Postgres session; only the token hash
 * is stored. Identical 401 payload for unknown user and wrong password.
 *
 * Brute-force protection (config/packages/rate_limiter.yaml): every attempt
 * consumes an account-keyed and an IP-keyed limiter before the password is
 * checked, so throttling can never be used to tell an existing account from an
 * absent one.
 */
final class LoginController extends AbstractController
{
    /** Token lifetime; short enough to bound replay, restart-free revocation on expiry. */
    private const TTL_SECONDS = 8 * 3600;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactory $loginAccountLimiter,
        private readonly RateLimiterFactory $loginIpLimiter,
    ) {
    }

    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '', true);
        if (!is_array($payload) || !is_string($payload['username'] ?? null) || !is_string($payload['password'] ?? null)) {
            return ErrorResponse::make('BAD_REQUEST', 'Malformed request body.', [], 400);
        }

        $username = $payload['username'];
        if ('' === $username || '' === $payload['password']) {
            return ErrorResponse::validationFailed([
                ['field' => 'username', 'issue' => 'username and password are required'],
            ]);
        }

        // Casing and surrounding whitespace must not create a fresh bucket.
        $accountKey = mb_strtolower(trim($username));
        $ipKey = $request->getClientIp() ?? 'unknown';

        $accountLimit = $this->loginAccountLimiter->create($accountKey)->consume(1);
        $ipLimit = $this->loginIpLimiter->create($ipKey)->consume(1);

        $throttled = self::throttledResponse($accountLimit, $ipLimit);
        if (null !== $throttled) {
            return $throttled;
        }

        // Housekeeping: lazily drop expired sessions.
        $this->sessions->deleteExpired();

        $user = $this->users->findByUsername($username);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$user instanceof User
            || !$user->isActive()
            || '' === $user->getPassword()
            || !$this->hasher->isPasswordValid($user, $payload['password'])
        ) {
            // Uniform payload: never reveal whether the username exists (REQ-081).
            return new JsonResponse(
                ['error' => ['code' => 'AUTH_INVALID', 'message' => 'Invalid credentials.', 'details' => []]],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        // A legitimate sign-in clears the penalty so a couple of typos do not
        // follow the user around for the rest of the window.
        $this->loginAccountLimiter->create($accountKey)->reset();
        $this->loginIpLimiter->create($ipKey)->reset();

        $token = bin2hex(random_bytes(32)); // 64 hex chars, CSPRNG
        $session = new Session($user, hash('sha256', $token), $now->modify('+'.self::TTL_SECONDS.' seconds'));
        $this->em->persist($session);
        $this->em->flush();

        return $this->json([
            'token' => $token,
            'expires_at' => QueryParamsHelper::atom($session->getExpiresAt()),
            'user' => [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'role' => $user->getRole()->value,
            ],
        ]);
    }

    /**
     * Contract error payload for a throttled attempt (HTTP 429).
     *
     * The message is identical whether or not the account exists, and states only
     * the cooldown, so it cannot be used to enumerate users.
     */
    private static function throttledResponse(RateLimit $accountLimit, RateLimit $ipLimit): ?JsonResponse
    {
        if ($accountLimit->isAccepted() && $ipLimit->isAccepted()) {
            return null;
        }

        $retryAt = null;
        foreach ([$accountLimit, $ipLimit] as $limit) {
            if ($limit->isAccepted()) {
                continue;
            }
            $candidate = $limit->getRetryAfter();
            if (null === $retryAt || $candidate > $retryAt) {
                $retryAt = $candidate;
            }
        }

        $seconds = null === $retryAt ? 60 : max(1, $retryAt->getTimestamp() - time());

        return new JsonResponse(
            [
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => sprintf('Too many sign-in attempts. Try again in %d seconds.', $seconds),
                    'details' => [['field' => 'retry_after', 'issue' => (string) $seconds]],
                ],
            ],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $seconds],
        );
    }
}
