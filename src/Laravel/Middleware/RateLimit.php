<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Laravel\Middleware;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\RateLimiter as RateLimiterService;
use Aporat\RateLimiter\Laravel\Facades\RateLimiter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Middleware to enforce rate limits on incoming requests.
 *
 * Applies rate limiting based on configured hourly, minute, and second thresholds,
 * exempting internal IPs (starting with "10.0."). Uses the RateLimiter facade to
 * track and enforce limits.
 */
final class RateLimit
{
    /**
     * Handle an incoming request and apply rate limiting.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  Closure  $next  The next middleware in the stack
     * @return mixed The response after applying rate limits
     *
     * @throws RateLimitException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $clientIp = $request->getClientIp();

        // Exempt internal IPs starting with "10.0."
        if ($clientIp !== null && Str::startsWith($clientIp, '10.0.')) {
            return $next($request);
        }

        // Check if the IP is blocked
        RateLimiter::create($request)->checkIpAddress();

        // Apply rate limits from config
        $limiter = RateLimiter::create($request)->withClientIpAddress();
        $this->applyRateLimits($limiter);

        return $next($request);
    }

    /**
     * Apply configured rate limits to the RateLimiter instance.
     *
     * @param  RateLimiterService  $limiter  The configured RateLimiter instance
     *
     * @throws RateLimitException
     */
    private function applyRateLimits(RateLimiterService $limiter): void
    {
        $limits = [
            'hourly' => ['limit' => config('rate-limiter.limits.hourly', 0), 'interval' => 3600],
            'minute' => ['limit' => config('rate-limiter.limits.minute', 0), 'interval' => 60],
            'second' => ['limit' => config('rate-limiter.limits.second', 0), 'interval' => 1],
        ];

        foreach ($limits as $name => $settings) {
            if ($settings['limit'] > 0) {
                $limiter->withName("requests:$name")
                    ->withTimeInterval($settings['interval'])
                    ->limit($settings['limit']);
            }
        }
    }
}
