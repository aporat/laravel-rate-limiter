<?php

namespace Aporat\RateLimiter\Laravel\Facades;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the RateLimiter service in Laravel.
 *
 * Provides a static interface to configure and enforce rate limiting on requests,
 * including IP-based blocking, request tagging, and custom rate limit headers.
 *
 * @method static string                          getRequestTag()                                        Get the current request tag
 * @method static \Aporat\RateLimiter\RateLimiter create(Request $request)                               Create a new RateLimiter instance for a request
 * @method static \Aporat\RateLimiter\RateLimiter withClientIpAddress()                                  Configure rate limiting by client IP address
 * @method static \Aporat\RateLimiter\RateLimiter withRequestInfo()                                      Include request-specific information in the limiter
 * @method static \Aporat\RateLimiter\RateLimiter withName(string $name)                                 Set a custom name for the rate limiter
 * @method static \Aporat\RateLimiter\RateLimiter withRateLimitHeaders(bool $setHeaders = true)          Enable or disable rate limit headers in the response
 * @method static \Aporat\RateLimiter\RateLimiter withUserId(string $userId)                             Configure rate limiting by user ID
 * @method static \Aporat\RateLimiter\RateLimiter withTimeInterval(int $interval = 3600)                 Set the time interval for rate limiting in seconds
 * @method static \Aporat\RateLimiter\RateLimiter setRequestTag(string $requestTag)                      Set a custom request tag
 * @method static \Aporat\RateLimiter\RateLimiter withResponse(Response $response)                       Attach a response object to the limiter
 * @method static void                            blockIpAddress(string $ipAddress, int $secondsToBlock) Block an IP address for a specified duration
 * @method static void                            checkIpAddress()                                       Check if the current IP address is blocked
 * @method static bool                            isIpAddressBlocked()                                   Determine if the current IP is blocked
 * @method static int                             count()                                                Get the current request count within the limit window
 * @method static int                             limit(int $limit = 5000, int $amount = 1)              Set or adjust the rate limit and increment attempts
 * @method static int                             record(int $amount = 1)                                Record a specified number of attempts and return the current count
 * @method static void                            clear()                                                Reset the rate limit counters
 * @method static mixed                           getConfigValue(string $key)                            Retrieve a value from the rate-limiter config
 *
 * @see \Aporat\RateLimiter\RateLimiter
 */
class RateLimiter extends Facade
{
    /**
     * Get the registered name of the component in the service container.
     *
     * @return string The binding key for the RateLimiter service
     */
    protected static function getFacadeAccessor(): string
    {
        return 'rate-limiter';
    }
}
