<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Exceptions;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Thrown with `on_redis_failure` set to `closed` when Redis cannot be reached.
 *
 * Rendered as a 503 with Retry-After set to the remaining failure backoff. The
 * underlying Redis error is reported once per backoff period, not per request,
 * so this exception is not reported itself.
 */
class RateLimiterUnavailableException extends Exception implements HttpExceptionInterface
{
    public function __construct(
        protected int $retryAfter = 1,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Service Unavailable', 0, $previous);
    }

    public function getStatusCode(): int
    {
        return 503;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return ['Retry-After' => (string) max(1, $this->retryAfter)];
    }

    public function report(): bool
    {
        return true;
    }
}
