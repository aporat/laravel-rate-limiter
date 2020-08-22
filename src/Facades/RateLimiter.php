<?php

namespace Aporat\RateLimiter\Facades;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string getRequestTag()
 * @method static RateLimiter withName(string $name)
 * @method static RateLimiter withRequest(Request $request)
 * @method static RateLimiter withResponse(Response $response)
 * @method static RateLimiter withRateLimitHeaders(bool $set_headers = true)
 * @method static RateLimiter withClientIpAddress()
 * @method static RateLimiter withUserId(string $user_id)
 * @method static RateLimiter withTimeInternal(int $interval = 3600)
 * @method static void blockIpAddress(string $ip_address, int $seconds_to_block)
 * @method static void checkIpAddress()
 * @method static int count(): int
 * @method static int limit(int $limit = 5000, int $amount = 1): int
 * @method static int record($amount = 1): int
 * @method static mixed getConfigValue(string $key)
 *
 * @see \Aporat\RateLimiter\RateLimiter
 */

class RateLimiter extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'rate-limiter';
    }
}
