<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Facades;

use Aporat\RateLimiter\RateLimiter as RateLimiterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Facade for the RateLimiter service in Laravel.
 *
 * Provides a static interface to configure and enforce rate limiting on requests,
 * including IP-based blocking, request tagging, and custom rate limit headers.
 *
 * @method static RateLimiterService create(Request $request) Start a new limiter context for a request
 * @method static RateLimiterService withClientIpAddress() Scope the limit to the client IP address
 * @method static RateLimiterService withRequestInfo() Scope the limit to the request method and path
 * @method static RateLimiterService withUserId(string $userId) Scope the limit to a user identifier
 * @method static RateLimiterService withName(string $name) Append a custom segment to the limiter tag
 * @method static RateLimiterService withTimeInterval(int $interval = 3600) Set the window length in seconds
 * @method static RateLimiterService withResponse(Response $response) Attach a response to receive rate limit headers
 * @method static RateLimiterService withRateLimitHeaders(bool $setHeaders = true) Enable or disable rate limit headers
 * @method static RateLimiterService setRequestTag(string $requestTag = '') Replace the limiter tag outright
 * @method static string getRequestTag() Get the current limiter tag
 * @method static int count() Get the current count within the window
 * @method static int ttl() Get the seconds remaining in the current window
 * @method static int limit(int $limit = 5000, int $amount = 1) Record attempts and throw once the limit is passed
 * @method static int record(int $amount = 1) Record attempts and return the current count
 * @method static void clear() Reset the counter for the current tag
 * @method static bool isWhitelisted(string|null $ipAddress = null) Determine whether an IP is exempt from limiting
 * @method static void blockIpAddress(string $ipAddress, int|null $secondsToBlock = null) Block an IP address
 * @method static void unblockIpAddress(string $ipAddress) Lift a block on an IP address
 * @method static bool isIpAddressBlocked() Determine if the current request's IP is blocked
 * @method static void checkIpAddress() Throw if the current request's IP is blocked
 * @method static void flushAll() Delete every key in the rate limiter namespace
 * @method static mixed getConfigValue(string $key, mixed $default = null) Read a value from the rate-limiter config
 *
 * @see RateLimiterService
 */
class RateLimiter extends Facade
{
    /**
     * Get the registered name of the component in the service container.
     */
    protected static function getFacadeAccessor(): string
    {
        return RateLimiterService::class;
    }
}
