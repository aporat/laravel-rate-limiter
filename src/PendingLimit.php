<?php

declare(strict_types=1);

namespace Aporat\RateLimiter;

use Aporat\RateLimiter\Events\RateLimitExceeded;
use Aporat\RateLimiter\Exceptions\RateLimiterUnavailableException;
use Aporat\RateLimiter\Exceptions\RateLimitException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An immutable description of one rate limit.
 *
 * Every `with*()` method returns a new instance, so a builder can be shared or
 * reused without one call leaking into another.
 */
final readonly class PendingLimit
{
    /**
     * @param  list<string>  $segments  Key segments, in the order they were added
     */
    public function __construct(
        private RateLimiter $limiter,
        private ?Request $request = null,
        private array $segments = [],
        private int $interval = RateLimiter::DEFAULT_INTERVAL,
        private ?Response $response = null,
    ) {}

    /**
     * Key the limit on the client IP (IPv6 grouped by /64).
     *
     * @throws LogicException When there is no request or the request has no client IP
     */
    public function withClientIpAddress(): self
    {
        $ip = $this->requireRequest()->getClientIp();

        if ($ip === null || $ip === '') {
            throw new LogicException('The request has no client IP address to rate limit on.');
        }

        return $this->withSegments('ip', $this->limiter->ipSubject($ip));
    }

    /**
     * Key the limit on a user identifier.
     */
    public function withUserId(string|int $userId): self
    {
        return $this->withSegments('user', (string) $userId);
    }

    /**
     * Key the limit on a custom name.
     */
    public function withName(string $name): self
    {
        return $this->withSegments('name', $name);
    }

    /**
     * Key the limit on the request method and route.
     *
     * Uses the matched route's name, or its URI pattern (`users/{user}`), so the
     * number of keys stays bounded; falls back to the raw path when no route has
     * been matched yet.
     */
    public function withRequestInfo(): self
    {
        $request = $this->requireRequest();
        $route = $request->route();

        $identifier = $route instanceof Route
            ? ($route->getName() ?? $route->uri())
            : $request->getPathInfo();

        return $this->withSegments('route', $request->getMethod().' '.$identifier);
    }

    /**
     * Set the window length, in seconds.
     */
    public function withTimeInterval(int $interval = RateLimiter::DEFAULT_INTERVAL): self
    {
        if ($interval < 1) {
            throw new InvalidArgumentException('Rate limit interval must be at least 1 second.');
        }

        return new self($this->limiter, $this->request, $this->segments, $interval, $this->response);
    }

    /**
     * Attach a response that receives X-RateLimit-* headers when the limit passes.
     */
    public function withResponse(Response $response): self
    {
        return new self($this->limiter, $this->request, $this->segments, $this->interval, $response);
    }

    /**
     * Record attempts if they fit within the limit; throw when they do not.
     *
     * A refused attempt is not counted.
     *
     * @throws RateLimitException When the limit would be exceeded
     * @throws RateLimiterUnavailableException When Redis is down and `on_redis_failure` is `closed`
     */
    public function limit(int $limit, int $amount = 1): LimitResult
    {
        $result = $this->attempt($limit, $amount);

        if (! $result->allowed) {
            throw RateLimitException::fromResult($result, $this->request);
        }

        return $result;
    }

    /**
     * Record attempts if they fit within the limit, without throwing.
     *
     * @throws RateLimiterUnavailableException
     */
    public function attempt(int $limit, int $amount = 1): LimitResult
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The limit must be at least 1.');
        }

        $result = $this->limiter->attempt($this->segments, [$this->interval => $limit], $amount);

        if (! $result->allowed) {
            $this->limiter->dispatch(new RateLimitExceeded($result, $this->segments, $this->request));
        } elseif ($this->response instanceof Response) {
            $result->applyHeaders($this->response);
        }

        return $result;
    }

    /**
     * Record attempts unconditionally and return the new count (0 when Redis is
     * down and failing open). Useful for duplicate detection.
     *
     * @throws RateLimiterUnavailableException
     */
    public function record(int $amount = 1): int
    {
        $result = $this->limiter->attempt($this->segments, [$this->interval => -1], $amount);

        return $result->windows[0]->count ?? 0;
    }

    /**
     * The current count in the window.
     */
    public function count(): int
    {
        return $this->limiter->countFor($this->segments, $this->interval);
    }

    /**
     * Seconds left in the current window (0 when there is none).
     */
    public function ttl(): int
    {
        return $this->limiter->ttlFor($this->segments, $this->interval);
    }

    /**
     * Reset the counter.
     */
    public function clear(): void
    {
        $this->limiter->clearFor($this->segments, $this->interval);
    }

    /**
     * The key segments describing this limit.
     *
     * @return list<string>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    /**
     * The Redis key of this limit's counter (without the connection prefix).
     */
    public function key(): string
    {
        return $this->limiter->windowKey($this->segments, $this->interval);
    }

    private function withSegments(string ...$segments): self
    {
        return new self(
            $this->limiter,
            $this->request,
            [...$this->segments, ...array_values($segments)],
            $this->interval,
            $this->response,
        );
    }

    private function requireRequest(): Request
    {
        if (! $this->request instanceof Request) {
            throw new LogicException('No request given; use RateLimiter::create($request).');
        }

        return $this->request;
    }
}
