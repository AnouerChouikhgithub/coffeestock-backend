<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Session;
use App\Entity\User;
use App\Repository\SessionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Bearer-token authenticator (OD-8 option A, opaque Postgres sessions).
 * `Authorization: Bearer <token>`; only the SHA-256 hash of the token is looked up.
 * All failures resolve to the uniform contract error payload with `AUTH_REQUIRED` (REQ-080).
 */
final class TokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly SessionRepository $sessions,
    ) {
    }

    public function supports(Request $request): bool
    {
        // Narrowed from the interface's ?bool: this authenticator either owns the
        // request or defers, it is never "undecided".
        return null !== $request->headers->get('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw $this->failure();
        }

        $session = $this->sessions->findByTokenHash(hash('sha256', $m[1]));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$session instanceof Session || $session->isExpired($now)) {
            throw $this->failure();
        }

        $user = $session->getUser();
        if (!$user->isActive()) {
            throw $this->failure();
        }

        $request->attributes->set('auth_session', $session);

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // let the controller run
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Narrowed from ?Response: a failure always gets the contract payload, and
        // never "no response", which would fall through to the entry point.
        return self::unauthorized();
    }

    /**
     * EntryPoint: requests with no Authorization header land here (REQ-080 401).
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized();
    }

    private static function unauthorized(): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => [
                    'code' => 'AUTH_REQUIRED',
                    'message' => 'Authentication required.',
                    'details' => [],
                ],
            ],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    private function failure(): AuthenticationException
    {
        return new AuthenticationException('Missing or invalid credentials.');
    }
}
