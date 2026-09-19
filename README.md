# Laravel Rate Limiter

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/laravel-rate-limiter.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-rate-limiter)
[![Downloads](https://img.shields.io/packagist/dt/aporat/laravel-rate-limiter.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-rate-limiter)
[![Codecov](https://img.shields.io/codecov/c/github/aporat/laravel-rate-limiter?style=flat-square)](https://codecov.io/github/aporat/laravel-rate-limiter)
[![Laravel Version](https://img.shields.io/badge/Laravel-13.x-orange.svg?style=flat-square)](https://laravel.com/docs/13.x)
![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/aporat/laravel-rate-limiter/ci.yml?style=flat-square)
[![License](https://img.shields.io/packagist/l/aporat/laravel-rate-limiter.svg?style=flat-square)](https://github.com/aporat/laravel-rate-limiter/blob/master/LICENSE)

A flexible rate limiting middleware for Laravel applications, designed to throttle requests and actions using Redis.

## Features
- Configurable rate limits per hour, minute, and second.
- Flexible limiting by IP, user ID, request method, and custom tags.
- IP blocking for abuse prevention.
- Optional rate limit headers in responses.
- Redis-backed storage for scalability.

## Requirements
- **PHP**: 8.4 or higher
- **Laravel**: 12.x or 13.x
- **Redis**: Required for storage (ext-redis extension)
- **Composer**: Required for installation

## Installation
Install the package via [Composer](https://getcomposer.org/):

```bash
composer require aporat/laravel-rate-limiter
```

`RateLimiterServiceProvider` is registered automatically via Laravel's package
discovery. If auto-discovery is disabled, add it to `bootstrap/providers.php`:

```php
return [
    // ...
    Aporat\RateLimiter\RateLimiterServiceProvider::class,
];
```

The package deliberately does **not** register a `RateLimiter` class alias, because
that name is taken by Laravel's own `Illuminate\Support\Facades\RateLimiter`.
Import this package's facade by its full name instead:

```php
use Aporat\RateLimiter\Facades\RateLimiter;
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Aporat\RateLimiter\RateLimiterServiceProvider" --tag="config"
```

## Configuration
Edit `config/rate-limiter.php`:

```php
return [
    'limits' => [
        'hourly' => 3000,
        'minute' => 60,
        'second' => 10,
    ],

    // Exempt from the middleware. Plain addresses or CIDR ranges, IPv4 or IPv6.
    'whitelisted_ips' => ['127.0.0.1', '::1', '10.0.0.0/8'],

    // Add X-Rate-Limit-Limit / X-Rate-Limit-Remaining to middleware responses.
    'headers' => false,

    // When false, RateLimitException is handed back to your application's own
    // reporters instead of writing its own log line.
    'log_errors' => true,

    'block_seconds' => 86400,

    'redis' => [
        // Inherit host/port/credentials from a config/database.php connection.
        'connection' => env('RATE_LIMITER_REDIS_CONNECTION'),
        'host' => env('RATE_LIMITER_REDIS_HOST', '127.0.0.1'),
        'port' => env('RATE_LIMITER_REDIS_PORT', 6379),
        'username' => env('RATE_LIMITER_REDIS_USERNAME'),
        'password' => env('RATE_LIMITER_REDIS_PASSWORD'),
        'database' => env('RATE_LIMITER_REDIS_DB', 0),
        'prefix' => env('RATE_LIMITER_REDIS_PREFIX', 'rate-limiter'),
        'timeout' => env('RATE_LIMITER_REDIS_TIMEOUT', 2.0),
        'read_timeout' => env('RATE_LIMITER_REDIS_READ_TIMEOUT', 2.0),
    ],
];
```

Anything set explicitly under `redis` overrides the named `connection` it inherits
from. `prefix` is applied by the package rather than through `Redis::OPT_PREFIX`,
so the keys the package scans and deletes are the same names it writes.

Config is read live from the container on each call, so a runtime `Config::set()`
is picked up by the long-lived singleton.

## Usage

### Middleware
The provider registers the `rate.limiter` alias. Apply it to a route group:

```php
Route::middleware('rate.limiter')->group(function () {
    // ...
});
```

Or register it globally in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Aporat\RateLimiter\Middleware\RateLimit::class);
})
```

Each configured window (`hourly`, `minute`, `second`) gets its own counter keyed on
the client IP; a window set to `0` is skipped. Addresses matching
`whitelisted_ips` bypass the middleware entirely.

Put the middleware **after** your trusted-proxy middleware, so `getClientIp()`
returns the real client rather than the load balancer.

### Manual Rate Limiting
```php
use Aporat\RateLimiter\Facades\RateLimiter;

Route::post('/submit', function (Request $request) {
    RateLimiter::create($request)
        ->withUserId((string) $request->user()?->id ?: 'guest')
        ->withName('form_submission')
        ->withTimeInterval(3600)
        ->limit(5); // 5 submissions per hour

    return 'Submitted!';
});
```

`create()` resets the tag, window and attached response, so the singleton is safe
to reuse. `limit()` throws `RateLimitException` once the count passes the limit;
`record()` increments and returns the count without throwing, which is what you
want for duplicate-request detection:

```php
$count = RateLimiter::create($request)
    ->withRequestInfo()
    ->withUserId($userId)
    ->withTimeInterval(10)
    ->record();

if ($count > 1) {
    // duplicate within the window
}
```

The increment and its expiry are applied in a single Lua script, so a counter can
never be left without a window if the process dies mid-call, and a key that has
somehow lost its TTL gets one back on the next write.

### IP Blocking
```php
RateLimiter::blockIpAddress('192.168.1.1', 86400); // defaults to block_seconds
RateLimiter::unblockIpAddress('192.168.1.1');

if (RateLimiter::create($request)->isIpAddressBlocked()) {
    abort(403, 'Your IP is blocked.');
}
```

IPv6 addresses are grouped by their `/64` prefix for both counting and blocking,
so a single client cannot rotate through a subnet it already controls.

### Rate Limit Headers
```php
$response = new Response('OK');

RateLimiter::create($request)
    ->withResponse($response)
    ->withRateLimitHeaders()
    ->withTimeInterval(3600)
    ->limit(100);

return $response; // X-Rate-Limit-Limit and X-Rate-Limit-Remaining
```

### Exception handling
`RateLimitException` implements `HttpExceptionInterface`, so Laravel renders it as
a `429` with a `Retry-After` header out of the box. Two deliberate omissions:

- It does **not** extend Symfony's `HttpException`, because that class is on the
  framework's internal "don't report" list, which would silence your own reporters.
- It does **not** define `render()`, because an exception's own `render()` takes
  precedence over `$exceptions->render()` callbacks — the rendering decision stays
  with your application.

With `log_errors` set to `false`, `report()` returns `false` so Laravel continues on
to your registered report callbacks:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (RateLimitException $e) {
        // runs when rate-limiter.log_errors is false
    });
})
```

Request bodies and headers written to the log are redacted for the usual
credential keys (`authorization`, `cookie`, `password`, `x-auth-signature`, ...).

## Testing
The suite talks to a real Redis on `127.0.0.1:6379` (database 15), overridable with
`REDIS_HOST` and `REDIS_PORT`:

```bash
composer test
```

Static analysis and code style:

```bash
composer analyze
composer check
```

## Contributing
Contributions are welcome! Please:
1. Fork the repository.
2. Create a feature branch (`git checkout -b feature/new-feature`).
3. Commit your changes (`git commit -m "Add new feature"`).
4. Push to the branch (`git push origin feature/new-feature`).
5. Open a pull request.

Report issues at [GitHub Issues](https://github.com/aporat/laravel-rate-limiter/issues).

## License
This package is licensed under the [MIT License](LICENSE). See the [License File](LICENSE) for details.

## Support
- **Issues**: [GitHub Issues](https://github.com/aporat/laravel-rate-limiter/issues)
- **Source**: [GitHub Repository](https://github.com/aporat/laravel-rate-limiter)
