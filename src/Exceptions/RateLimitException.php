<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Exceptions;

use Aporat\RateLimiter\LimitResult;
use Exception;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Thrown when a rate limit is exceeded or a blocked IP makes a request.
 *
 * Laravel renders it as a 429 with Retry-After and X-RateLimit-* headers, because
 * it implements HttpExceptionInterface. It does not define render(), so your
 * `$exceptions->render()` callbacks still decide the body.
 *
 * report() always handles the exception itself: with `rate-limiter.log_errors`
 * on it writes one warning line (method, path, client IP, retry-after; never
 * the query string, input or headers), and either way it stops Laravel from
 * logging every 429 at error level with a stack trace. Listen to the
 * RateLimitExceeded event to react to refusals instead.
 */
class RateLimitException extends Exception implements HttpExceptionInterface
{
    /**
     * @param  array<string, string>  $headers  Headers to send with the 429
     */
    public function __construct(
        string $message = 'Too Many Requests',
        protected array $headers = [],
        protected ?Request $request = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Build the exception for a refused attempt.
     */
    public static function fromResult(LimitResult $result, ?Request $request = null): self
    {
        return new self('Too Many Requests', $result->headers(), $request);
    }

    /**
     * Build the exception for a request from a blocked IP address.
     */
    public static function blocked(int $secondsLeft, ?Request $request = null): self
    {
        $secondsLeft = max(1, $secondsLeft);

        return new self('Too Many Requests', [
            'Retry-After' => (string) $secondsLeft,
            'X-RateLimit-Reset' => (string) (time() + $secondsLeft),
        ], $request);
    }

    public function getStatusCode(): int
    {
        return 429;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Seconds until the caller may retry, if known.
     */
    public function getRetryAfter(): ?int
    {
        return isset($this->headers['Retry-After']) ? (int) $this->headers['Retry-After'] : null;
    }

    public function getRequest(): ?Request
    {
        return $this->request;
    }

    /**
     * Log one warning line when `log_errors` is on; never fall through to Laravel's
     * default error-level reporting.
     */
    public function report(): bool
    {
        $container = Container::getInstance();
        $config = $container->bound('config') ? $container->make('config') : null;

        if (! $config instanceof Config || ! $config->get('rate-limiter.log_errors', false) || ! $container->bound('log')) {
            return true;
        }

        $logger = $container->make('log');

        if ($logger instanceof LoggerInterface) {
            $logger->warning('Rate limit exceeded', array_filter([
                'method' => $this->request?->getMethod(),
                'path' => $this->request?->getPathInfo(),
                'ip' => $this->request?->getClientIp(),
                'retry_after' => $this->getRetryAfter(),
            ], static fn ($value) => $value !== null));
        }

        return true;
    }
}
