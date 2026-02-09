<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Rate limit the /generate endpoint to prevent abuse.
 */
final readonly class RateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.generator')]
        private RateLimiterFactory $generatorLimiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 10], // High priority for early exit
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        // Early return for sub-requests
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Only apply to /generate route - early return for better performance
        if ('app_generate' !== $request->attributes->get('_route')) {
            return;
        }

        // Get client IP with X-Forwarded-For support
        $clientIp = $this->getClientIp($request);

        // Create limiter for this IP
        $limiter = $this->generatorLimiter->create($clientIp);

        // Consume a token
        $limit = $limiter->consume();

        // Fast path: request accepted
        if ($limit->isAccepted()) {
            return;
        }

        // Rate limit exceeded - return 429 response (retry_after_seconds = relative, for clients)
        $retryAfter = $limit->getRetryAfter();
        $retryAfterSeconds = max(0, $retryAfter->getTimestamp() - time());
        $event->setController(function () use ($limit, $retryAfterSeconds): Response {
            return new JsonResponse(
                [
                    'error' => 'Rate limit exceeded. Please try again later.',
                    'retry_after_seconds' => $retryAfterSeconds,
                ],
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                    'X-RateLimit-Limit' => (string) $limit->getLimit(),
                    'Retry-After' => (string) $retryAfterSeconds,
                ]
            );
        });
    }

    /**
     * Get client IP address with proxy support.
     */
    private function getClientIp(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }
}
