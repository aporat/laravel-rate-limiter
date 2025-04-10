# Laravel Rate Limiter

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/laravel-rate-limiter.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-rate-limiter)
[![Monthly Downloads](https://img.shields.io/packagist/dm/aporat/laravel-rate-limiter.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-rate-limiter)
[![Codecov](https://img.shields.io/codecov/c/github/aporat/laravel-rate-limiter?style=flat-square)](https://codecov.io/github/aporat/laravel-rate-limiter)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-orange.svg?style=flat-square)](https://laravel.com/docs/12.x)
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
- **Laravel**: 10.x, 11.x or 12.x
- **Redis**: Required for storage (ext-redis extension)
- **Composer**: Required for installation

## Installation
Install the package via [Composer](https://getcomposer.org/):

```bash
composer require aporat/laravel-rate-limiter
```

The service provider (`RateLimiterServiceProvider`) is automatically registered via Laravel’s package discovery. If auto-discovery is disabled, add it to `config/app.php`:

```php
'providers' => [
    // ...
    Aporat\RateLimiter\Laravel\RateLimiterServiceProvider::class,
],
```

Optionally, register the facade for cleaner syntax:

```php
'aliases' => [
    // ...
    'RateLimiter' => Aporat\RateLimiter\Laravel\Facades\RateLimiter::class,
],
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Aporat\RateLimiter\Laravel\RateLimiterServiceProvider" --tag="config"
```

This copies `rate-limiter.php` to your `config/` directory.

## Configuration
Edit `config/rate-limiter.php` to adjust limits and Redis settings:

```php
return [
    'limits' => [
        'hourly' => 3000,
        'minute' => 60,
        'second' => 10,
    ],
    'log_errors' => true, // Set to false to disable logging of rate limit violations
    'redis' => [
        'host' => env('RATE_LIMITER_REDIS_HOST', '127.0.0.1'),
        'port' => env('RATE_LIMITER_REDIS_PORT', 6379),
        'database' => env('RATE_LIMITER_REDIS_DB', 0),
        'prefix' => env('RATE_LIMITER_REDIS_PREFIX', 'rate-limiter:'),
    ],
];

```

Add these to your `.env` file if needed:

```
RATE_LIMITER_REDIS_HOST=127.0.0.1
RATE_LIMITER_REDIS_PORT=6379
RATE_LIMITER_REDIS_DB=0
RATE_LIMITER_REDIS_PREFIX=rate-limiter:
```

## Usage

### Middleware
Apply rate limiting globally by registering the middleware in `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \Aporat\RateLimiter\Laravel\Middleware\RateLimit::class,
];
```

Or apply it to specific routes:

```php
Route::get('/api/test', function () {
    return 'Hello World';
})->middleware('Aporat\RateLimiter\Laravel\Middleware\RateLimit');
```

The middleware uses the configured limits (`hourly`, `minute`, `second`) and exempts IPs starting with `10.0.`.

### Manual Rate Limiting
Use the `RateLimiter` facade for custom limiting:

```php
use Aporat\RateLimiter\Laravel\Facades\RateLimiter;

Route::post('/submit', function (Request $request) {
    RateLimiter::create($request)
        ->withUserId(auth()->id() ?? 'guest')
        ->withName('form_submission')
        ->withTimeInterval(3600)
        ->limit(5); // 5 submissions per hour

    return 'Submitted!';
});
```

### IP Blocking
Block an IP manually:

```php
RateLimiter::blockIpAddress('192.168.1.1', 86400); // Block for 24 hours
```

Check if an IP is blocked:

```php
if (RateLimiter::isIpAddressBlocked()) {
    abort(403, 'Your IP is blocked.');
}
```

### Rate Limit Headers
Add headers to responses:

```php
$response = new Response('OK');
RateLimiter::create($request)
    ->withResponse($response)
    ->withRateLimitHeaders()
    ->limit(100);

return $response; // Includes X-Rate-Limit-Limit and X-Rate-Limit-Remaining
```

## Testing
Run the test suite:

```bash
composer test
```

Generate coverage reports:

```bash
composer test-coverage
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
