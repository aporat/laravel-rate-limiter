# Laravel Rate Limiter

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/laravel-rate-limiter.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-rate-limiter)
[![Downloads](https://img.shields.io/packagist/dt/aporat/laravel-rate-limiter.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-rate-limiter)
[![Codecov](https://img.shields.io/codecov/c/github/aporat/laravel-rate-limiter?style=flat-square)](https://codecov.io/github/aporat/laravel-rate-limiter)
![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/aporat/laravel-rate-limiter/ci.yml?style=flat-square)
[![License](https://img.shields.io/packagist/l/aporat/laravel-rate-limiter.svg?style=flat-square)](https://github.com/aporat/laravel-rate-limiter/blob/main/LICENSE)

Redis-backed, multi-window rate limiting for Laravel: a route middleware with per-IP, per-user and per-route keys, plus a fluent API for limiting anything else.

## Features

- Several windows at once (per second, minute, hour, day), checked **atomically in one Redis script**. A request is counted only if every window allows it, so a refused burst never uses up your hourly budget.
- Middleware parameters: `rate.limiter:60,1` or `rate.limiter:minute=60,hourly=1000,key=user+route,name=api`.
- Laravel-style `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` and `Retry-After` headers, always describing the most restrictive window.
- Any Redis client Laravel supports: phpredis or predis, single node or cluster, TLS, `REDIS_URL`.
- IP blocking, IPv6 grouped by /64, a CIDR whitelist.
- A configurable fail-open / fail-closed mode for when Redis is down, with a backoff so a dead Redis doesn't add a timeout to every request.
- `RateLimitExceeded` and `IpBlocked` events.
- Octane-safe: no request state on the singleton; each limit is an immutable builder.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Redis, through Laravel's Redis layer: the `redis` PHP extension (phpredis, Laravel's default) or `predis/predis`

## Installation

```bash
composer require aporat/laravel-rate-limiter
php artisan vendor:publish --provider="Aporat\RateLimiter\RateLimiterServiceProvider" --tag=config
```

The service provider is auto-discovered. The facade is `Aporat\RateLimiter\Facades\RateLimiter`. It isn't aliased globally, because Laravel already has a `RateLimiter` facade.

## Configuration

`config/rate-limiter.php`:

| Key | Default | Description |
|---|---|---|
| `connection` | `default` (`RATE_LIMITER_REDIS_CONNECTION`) | A connection from `config/database.php` → `redis`. |
| `prefix` | `rate-limiter` | Namespace for the package's keys, added after the connection's own `options.prefix`. |
| `limits` | `hourly=3000, minute=60, second=10` | Middleware windows when a route passes none. Windows: `second`, `minute`, `hourly`, `daily`. `0` disables a window. |
| `key` | `ip` | What the middleware counts by when a route passes no `key`. |
| `whitelisted_ips` | `127.0.0.1/32`, `::1/128` | Addresses or CIDR ranges that skip the middleware. |
| `headers` | `true` | Add `X-RateLimit-*` headers to successful responses (429 responses always get them). |
| `log_errors` | `false` | Log one warning per 429. |
| `block_seconds` | `86400` | Default length of `blockIpAddress()`. |
| `on_redis_failure` | `open` | `open` or `closed`; see [When Redis is down](#when-redis-is-down). |
| `failure_backoff_seconds` | `5` | How long to stop calling Redis after a failure. |

Give the limiter its own Redis connection with short timeouts so a slow Redis can't stall requests:

```php
// config/database.php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    // ...
    'rate-limiter' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', '6379'),
        'password' => env('REDIS_PASSWORD'),
        'database' => env('RATE_LIMITER_REDIS_DB', '2'),
        'timeout' => 0.5,
        'read_timeout' => 0.5,
    ],
],
```

```dotenv
RATE_LIMITER_REDIS_CONNECTION=rate-limiter
```

### Behind a load balancer or proxy

The middleware keys on `$request->getClientIp()`, so it depends on Laravel's [trusted proxy](https://laravel.com/docs/requests#configuring-trusted-proxies) setup:

- **Proxies not trusted:** every request appears to come from the load balancer, so all clients share one bucket. A whitelisted private range such as `10.0.0.0/8` would switch limiting off for everyone, which is why v6 whitelists loopback only.
- **Trusting `*`:** any client can put whatever it likes in `X-Forwarded-For`, which gives it a fresh bucket per request, or a whitelisted address. Trust only your proxies' addresses.

## The middleware

The provider registers the `rate.limiter` alias.

```php
Route::middleware('rate.limiter')->group(...);                        // windows and key from config
Route::middleware('rate.limiter:60,1')->group(...);                   // 60 per 1 minute, like `throttle`
Route::middleware('rate.limiter:minute=60,hourly=1000')->group(...);  // explicit windows
Route::middleware('rate.limiter:minute=30,key=user,name=api')->group(...);
Route::middleware('rate.limiter:minute=5,key=ip+route')->post('/login', ...);
```

| Parameter | Meaning |
|---|---|
| `N,M` (positional) | `N` requests per `M` minutes (`M` defaults to 1), like Laravel's `throttle`. Can be followed by named options. |
| `second=`, `minute=`, `hourly=`/`hour=`, `daily=`/`day=` | Limit per window. When any window is given, only the given windows apply. `0` disables a window. |
| `key=` | `ip`, `user` or `route`, combined with `+` (e.g. `user+route`). `user` is `$request->user()->getAuthIdentifier()`, falling back to the IP for guests. `route` is the route name, or its URI pattern (`users/{user}`), so the number of keys stays bounded. Default: config `key`. |
| `name=` | Gives this group its own counters. Without a name, every unnamed `rate.limiter` route with the same key shares one budget per window. |

Unknown or malformed parameters throw an `InvalidArgumentException` on the first request, so typos don't silently fall back to the defaults.

You can also build the string in PHP:

```php
use Aporat\RateLimiter\Middleware\RateLimit;

Route::middleware(RateLimit::with(minute: 60, hourly: 1000, key: ['user', 'route'], name: 'api'));
// 'rate.limiter:minute=60,hourly=1000,key=user+route,name=api'
```

Order of operations for each request:

1. A whitelisted IP passes straight through.
2. A blocked IP gets a 429 with `Retry-After` set to the time left on the block.
3. Every window is checked, then the request is counted in all of them, or in none if any window is full.

A request without a client IP, and with nothing else to key on (for example `key=ip` with no IP), is let through unlimited instead of sharing one bucket with every other such request. A real web server always provides one.

To register it globally:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Aporat\RateLimiter\Middleware\RateLimit::class);
})
```

It is independent of Laravel's own `throttle` middleware and `RateLimiter` facade: different storage and different keys. Use one or the other on a route, not both.

### Headers

Successful responses (when `headers` is on):

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
X-RateLimit-Reset: 1760000060
```

These describe the most restrictive window: the one with the fewest requests left, with ties going to the window that resets last.

A refused request is a `429 Too Many Requests` with the same headers plus `Retry-After`, describing the full window that resets last. Every full window must reset before a retry can succeed.

### Fixed windows and bursts

Each window is a fixed window that starts at its first hit and lasts its length. The usual fixed-window caveat applies: a client can spend a full window's budget just before the window resets and another full budget just after it. With `second=10`, up to 20 requests can land within a fraction of a second across the boundary. Pair a short window with a longer one (for example `second=10,minute=300`) if that matters.

## Limiting anything else

`RateLimiter::create()` returns an immutable `PendingLimit`. Every `with*()` call returns a new instance, so nothing leaks between calls.

```php
use Aporat\RateLimiter\Facades\RateLimiter;
use Aporat\RateLimiter\Exceptions\RateLimitException;

// 5 submissions per hour per user; throws RateLimitException (rendered as 429)
RateLimiter::create($request)
    ->withUserId($request->user()->id)
    ->withName('form_submission')
    ->withTimeInterval(3600)
    ->limit(5);

// Check without throwing
$result = RateLimiter::withName('export')->withUserId($userId)->withTimeInterval(60)->attempt(2);
if (! $result->allowed) {
    return back()->withErrors("Try again in {$result->retryAfter()}s");
}

// Count unconditionally (e.g. duplicate detection)
$count = RateLimiter::create($request)->withRequestInfo()->withUserId($userId)->withTimeInterval(10)->record();

// Copy headers onto a response
RateLimiter::create($request)->withClientIpAddress()->withResponse($response)->limit(100);

// Several windows at once, atomically
RateLimiter::attempt(['user', (string) $userId], [60 => 10, 3600 => 100]);
```

| Method | Notes |
|---|---|
| `withClientIpAddress()` | Needs a request with a client IP. IPv6 is grouped by /64. |
| `withUserId($id)`, `withName($name)` | Free-form; segments are escaped, so `'a:b'` can't collide with `'a'` + `'b'`. |
| `withRequestInfo()` | Method plus route name or URI pattern (the raw path when no route is matched). |
| `withTimeInterval($seconds)` | Window length, default 3600. |
| `limit($max, $amount = 1)` | Returns a `LimitResult`, or throws `RateLimitException`. A refused attempt isn't counted. |
| `attempt($max, $amount = 1)` | Same as `limit()`, without throwing. |
| `record($amount = 1)` | Always counts, and returns the new count. |
| `count()`, `ttl()`, `clear()` | Inspect or reset the counter. |

`$max` and `$amount` must be at least 1.

## Blocking IPs

```php
RateLimiter::blockIpAddress('203.0.113.7');        // block_seconds (default 24h)
RateLimiter::blockIpAddress('203.0.113.7', 600);
RateLimiter::unblockIpAddress('203.0.113.7');
RateLimiter::isIpAddressBlocked('203.0.113.7');    // bool
RateLimiter::blockedFor('203.0.113.7');            // seconds left
```

The middleware answers blocked IPs with a 429 and `Retry-After`. IPv6 blocks cover the whole /64.

## When Redis is down

| `on_redis_failure` | Behaviour |
|---|---|
| `open` (default) | Requests go through unchecked (no rate limit headers). `attempt()`/`limit()` return an allowed result with `degraded = true`; `record()`, `count()` and `ttl()` return 0. |
| `closed` | Requests get `503 Service Unavailable` with `Retry-After` (`RateLimiterUnavailableException`). |

After a failure, the process doesn't contact Redis again for `failure_backoff_seconds`, so a dead or unreachable Redis costs one timeout per backoff period, not one per request. The Redis exception is passed to `report()` once per backoff period. The backoff is per PHP process: per FPM worker, or per Octane worker.

Admin operations (`blockIpAddress`, `unblockIpAddress`, `clear`, `flushAll`) always throw on a Redis error.

## Events

- `Aporat\RateLimiter\Events\RateLimitExceeded`: on every refused attempt (middleware and builder). Carries `result`, `segments` and `request`.
- `Aporat\RateLimiter\Events\IpBlocked`: on `blockIpAddress()`. Carries `ipAddress` and `seconds`.

## Logging

`RateLimitException::report()` stops Laravel from logging every 429 as an error with a stack trace. With `log_errors` on, it writes a single `warning`, `Rate limit exceeded`, with the method, path, client IP and retry-after. It never logs the query string, input or headers. To react to refusals, listen for `RateLimitExceeded`.

## Octane and long-running workers

The `RateLimiter` singleton holds no request state. It resolves config and the Redis connection from the current container on every call, so runtime `config()` changes are picked up. Builders are immutable objects created per call. The only process-wide state is the Redis failure backoff, which is intended.

## Redis Cluster

All windows of one subject share a `{...}` hash tag, so the multi-window script touches a single slot. `flushAll()` scans one node and throws on cluster connections.

## Upgrading from v5

v6 is a breaking release.

**Storage and config**

- **Redis access** goes through Laravel's Redis manager. The `redis.*` block (host, port, password, database, timeouts) is replaced by `connection`, the name of a `database.redis` connection, and `redis.prefix` moves to the top-level `prefix`.
- **Keys** have a new format. Existing v5 counters and blocks are ignored; they expire on their own. Blocks made with v5 must be re-applied.
- **Defaults:** `whitelisted_ips` no longer includes `10.0.0.0/8`, `log_errors` is now `false`, and `headers` is now `true`.

**Middleware**

- It takes parameters, checks every window before counting, and no longer counts refused requests.
- **Headers** are renamed from `X-Rate-Limit-Limit`/`X-Rate-Limit-Remaining` to `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`, and they now report the most restrictive window. 429 and blocked responses carry them, with `Retry-After`.

**Builder API**

- `create()` returns an immutable `PendingLimit` instead of mutating the singleton. Chains still read the same, but a builder can't be continued across calls by calling the facade again.
- `limit()` returns a `LimitResult` instead of an int.
- These methods are removed:
  - `withRateLimitHeaders()`: use `withResponse()`.
  - `setRequestTag()` / `getRequestTag()`: use `segments()` / `key()`.
  - `checkIpAddress()`: the middleware handles blocks.
- `isIpAddressBlocked()` now takes the IP address.
- A limit with no key segment throws, instead of silently returning 0.
- `withClientIpAddress()` throws when the request has no IP.
- Amounts, limits and block durations below 1 throw `InvalidArgumentException`.

**Exceptions**

- The `RateLimitException` constructor is now `($message, $headers, $request)`.
- `report()` always handles the exception itself.

**Requirements**

- PHP 8.3+.
- `ext-redis` is no longer required: either phpredis or predis works.

## Testing

The suite needs a Redis server (database 15; `REDIS_HOST`/`REDIS_PORT` override the address) and runs against either client:

```bash
composer test                       # phpredis
REDIS_CLIENT=predis composer test   # predis
composer check                      # Pint
composer analyze                    # PHPStan (level 8)
```

## License

MIT. See [LICENSE](https://github.com/aporat/laravel-rate-limiter/blob/main/LICENSE).
