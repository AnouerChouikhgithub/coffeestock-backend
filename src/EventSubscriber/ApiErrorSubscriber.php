<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Normalises errors raised by the framework itself onto the uniform payload that
 * docs/api-contract.md requires of every error response.
 *
 * Controllers already return that shape through ErrorResponse, but exceptions that
 * never reach a controller — a firewall denial, an unmatched route, a rejected HTTP
 * method — were falling through to Symfony's error page and returning HTML to a
 * JSON API. That both broke the documented contract and risked leaking internal
 * detail, so the mask below is deliberately total for `/api`.
 */
final class ApiErrorSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Must run after RouterListener (-64), so a routing failure has already
            // been normalised into a NotFoundHttpException, but before HttpKernel's
            // ErrorListener (-128). ErrorListener renders Symfony's HTML error page,
            // and RequestEvent::setResponse() calls stopPropagation() — so whichever
            // listener sets the response first wins and any later one never runs.
            KernelEvents::EXCEPTION => ['onKernelException', -100],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        [$status, $code, $message] = $this->describe($event->getThrowable());

        $event->setResponse(new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => [],
            ],
        ], $status));
    }

    /**
     * @return array{int, string, string}
     */
    private function describe(\Throwable $throwable): array
    {
        return match (true) {
            $throwable instanceof AccessDeniedException,
            $throwable instanceof AccessDeniedHttpException => [Response::HTTP_FORBIDDEN, 'FORBIDDEN', 'Insufficient permissions for this operation.'],
            $throwable instanceof AuthenticationException => [Response::HTTP_UNAUTHORIZED, 'AUTH_REQUIRED', 'Authentication required.'],
            $throwable instanceof NotFoundHttpException => [Response::HTTP_NOT_FOUND, 'NOT_FOUND', 'Resource not found.'],
            $throwable instanceof MethodNotAllowedHttpException => [Response::HTTP_METHOD_NOT_ALLOWED, 'METHOD_NOT_ALLOWED', 'Method not allowed for this endpoint.'],
            $throwable instanceof UnprocessableEntityHttpException => [Response::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_FAILED', 'Request validation failed.'],
            $throwable instanceof BadRequestHttpException => [Response::HTTP_BAD_REQUEST, 'BAD_REQUEST', 'Malformed request.'],
            $throwable instanceof HttpExceptionInterface => [$throwable->getStatusCode(), 'HTTP_ERROR', 'Request could not be completed.'],
            // Anything unmapped is an internal fault. The message is intentionally
            // generic: the exception is already logged, and a stack trace, SQL
            // fragment or file path must never reach the client.
            default => [Response::HTTP_INTERNAL_SERVER_ERROR, 'INTERNAL_ERROR', 'Unexpected server error.'],
        };
    }
}
