<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Facades;

use Aporat\RateLimiter\LimitResult;
use Aporat\RateLimiter\PendingLimit;
use Aporat\RateLimiter\RateLimiter as RateLimiterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * Every builder method returns a new, immutable PendingLimit.
 *
 * @method static PendingLimit create(?Request $request = null) Start a limit, optionally for a request
 * @method static PendingLimit for(?Request $request = null) Alias of create()
 * @method static PendingLimit withUserId(string|int $userId) Start a limit keyed on a user id
 * @method static PendingLimit withName(string $name) Start a limit keyed on a name
 * @method static LimitResult attempt(list<string> $segments, array<int, int> $windows, int $amount = 1) Check and count several windows atomically
 * @method static bool isWhitelisted(?string $ipAddress) Whether an IP is exempt from the middleware
 * @method static void blockIpAddress(string $ipAddress, ?int $secondsToBlock = null) Block an IP address
 * @method static void unblockIpAddress(string $ipAddress) Lift a block on an IP address
 * @method static bool isIpAddressBlocked(?string $ipAddress) Whether an IP is blocked
 * @method static int blockedFor(?string $ipAddress) Seconds left on an IP block (0 when not blocked)
 * @method static void flushAll() Delete every key in the package namespace
 * @method static mixed getConfigValue(string $key, mixed $default = null) Read a rate-limiter config value
 *
 * @see RateLimiterService
 */
class RateLimiter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RateLimiterService::class;
    }
}
