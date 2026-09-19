<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Middleware;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\RateLimiter as RateLimiterService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to enforce rate limits on incoming requests.
 *
 * Applies the hourly, minute and second thresholds from config to the client IP,
 * skipping any address listed in `rate-limiter.whitelisted_ips` (plain addresses
 * or CIDR ranges). Each window gets its own counter key; the limiter instance is
 * re-created per window so tags do not accumulate across them.
 */
final class RateLimit
{
    /** Window name => length in seconds, longest first. */
    private const array WINDOWS = [
        'hourly' => 3600,
        'minute' => 60,
        'second' => 1,
    ];

    public function __construct(private readonly RateLimiterService $limiter) {}

    /**
     * Handle an incoming request and apply rate limiting.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Closure(Request): Response  $next  The next middleware in the stack
     * @return Response The response after applying rate limits
     *
     * @throws RateLimitException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->limiter->create($request)->isWhitelisted()) {
            return $next($request);
        }

        $this->limiter->create($request)->checkIpAddress();

        $headers = $this->applyRateLimits($request);

        $response = $next($request);

        if ($headers !== null) {
            $response->headers->set('X-Rate-Limit-Limit', (string) $headers[0]);
            $response->headers->set('X-Rate-Limit-Remaining', (string) $headers[1]);
        }

        return $response;
    }

    /**
     * Apply each configured window to its own counter.
     *
     * @return array{int, int}|null The [limit, remaining] pair of the tightest configured
     *                              window, or null when headers are disabled or nothing is limited
     *
     * @throws RateLimitException
     */
    private function applyRateLimits(Request $request): ?array
    {
        $withHeaders = (bool) $this->limiter->getConfigValue('headers', false);
        $headers = null;

        foreach (self::WINDOWS as $name => $interval) {
            $limit = (int) $this->limiter->getConfigValue("limits.{$name}", 0);

            if ($limit < 1) {
                continue;
            }

            $count = $this->limiter->create($request)
                ->withClientIpAddress()
                ->withName("requests:{$name}")
                ->withTimeInterval($interval)
                ->limit($limit);

            // WINDOWS is ordered longest to shortest, so the last window that ran
            // is the tightest one and the most useful to report back to the client.
            if ($withHeaders) {
                $headers = [$limit, max(0, $limit - $count)];
            }
        }

        return $headers;
    }
}
