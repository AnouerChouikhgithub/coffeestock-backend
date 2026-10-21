<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Session;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/logout — revoke the caller's bearer token.
 *
 * Without this, a token stays valid for its full 8-hour lifetime even after the
 * user signs out, so a copied token outlives the session it came from. The
 * current session is handed to us as the `auth_session` request attribute by
 * TokenAuthenticator; deleting the row makes subsequent requests 401.
 *
 * Idempotent by construction: the route requires authentication, and the very
 * next use of the token falls through to an empty session.
 */
final class LogoutController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $session = $request->attributes->get('auth_session');
        if ($session instanceof Session) {
            $this->em->remove($session);
            $this->em->flush();
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
