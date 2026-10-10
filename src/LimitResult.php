<?php

declare(strict_types=1);

namespace Aporat\RateLimiter;

use Symfony\Component\HttpFoundation\Response;

/**
 * The outcome of checking one or more windows atomically.
 */
final readonly class LimitResult
{
    /**
     * @param  bool  $allowed  Whether every window allowed the attempt (and it was counted)
     * @param  list<WindowState>  $windows  Every window that was checked
     * @param  bool  $degraded  True when Redis was unavailable and the attempt was let through unchecked
     */
    public function __construct(
        public bool $allowed,
        public array $windows,
        public bool $degraded = false,
    ) {}

    /**
     * A result for an attempt that was let through without reaching Redis.
     */
    public static function degraded(): self
    {
        return new self(true, [], true);
    }

    /**
     * The window to report to the client.
     *
     * When the attempt was denied this is the exceeded window that resets last
     * (every exceeded window must reset before a retry can succeed). When it was
     * allowed it is the window with the fewest remaining hits, ties going to the
     * window that resets last.
     */
    public function mostRestrictive(): ?WindowState
    {
        $candidates = $this->allowed
            ? $this->windows
            : array_values(array_filter($this->windows, static fn (WindowState $w) => $w->exceeded));

        if ($candidates === []) {
            $candidates = $this->windows;
        }

        $best = null;

        foreach ($candidates as $window) {
            if ($best === null) {
                $best = $window;

                continue;
            }

            $better = $this->allowed
                ? $window->remaining() < $best->remaining()
                    || ($window->remaining() === $best->remaining() && $window->resetsInMs > $best->resetsInMs)
                : $window->resetsInMs > $best->resetsInMs;

            if ($better) {
                $best = $window;
            }
        }

        return $best;
    }

    /**
     * Seconds until a retry can succeed (0 when the attempt was allowed).
     */
    public function retryAfter(): int
    {
        if ($this->allowed) {
            return 0;
        }

        return $this->mostRestrictive()?->resetsIn() ?? 1;
    }

    /**
     * Laravel-style rate limit headers for this result.
     *
     * @return array<string, string>
     */
    public function headers(?int $now = null): array
    {
        $window = $this->mostRestrictive();

        if ($window === null) {
            return [];
        }

        $now ??= time();

        $headers = [
            'X-RateLimit-Limit' => (string) $window->limit,
            'X-RateLimit-Remaining' => (string) ($this->allowed ? $window->remaining() : 0),
            'X-RateLimit-Reset' => (string) ($now + $window->resetsIn()),
        ];

        if (! $this->allowed) {
            $headers['Retry-After'] = (string) $this->retryAfter();
        }

        return $headers;
    }

    /**
     * Copy the rate limit headers onto a response.
     */
    public function applyHeaders(Response $response): Response
    {
        foreach ($this->headers() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
