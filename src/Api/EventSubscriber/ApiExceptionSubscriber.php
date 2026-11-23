<?php

declare(strict_types=1);

namespace App\Api\EventSubscriber;

use App\Schema\SchemaValidationException;
use App\Storage\Exception\DocumentNotFoundException;
use App\Storage\Exception\StaleVersionException;
use App\Validation\BusinessValidationException;
use App\Validation\Violation;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns the errors of /api/* into `application/problem+json` (RFC 7807) with the status the client needs.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 10]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $e = $event->getThrowable();
        $problem = match (true) {
            $e instanceof DocumentNotFoundException => [404, 'Not found', $e->getMessage()],
            $e instanceof SchemaValidationException => [422, 'Schema validation failed', $e->getMessage(), ['errors' => $e->getErrors()]],
            $e instanceof BusinessValidationException => [422, 'Business rules broken', $e->getMessage(), [
                'violations' => array_map(static fn (Violation $v): array => $v->toArray(), $e->getViolations()),
            ]],
            $e instanceof StaleVersionException => [409, 'Stale version', $e->getMessage(), ['expected' => $e->expected, 'current' => $e->current]],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'HTTP error', $e->getMessage()],
            $e instanceof \JsonException, $e instanceof \InvalidArgumentException => [400, 'Bad request', $e->getMessage()],
            default => null,
        };
        if ($problem === null) {
            return; // unexpected error: left to Symfony
        }

        [$status, $title, $detail] = $problem;
        $extra = $problem[3] ?? [];
        $body = ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail] + $extra;
        $event->setResponse(new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']));
    }
}
