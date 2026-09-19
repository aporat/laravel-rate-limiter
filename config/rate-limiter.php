<?php

/**
 * Configuration for the Laravel Rate Limiter package.
 *
 * @see https://github.com/aporat/laravel-rate-limiter
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Thresholds
    |--------------------------------------------------------------------------
    |
    | The maximum number of requests the `rate.limiter` middleware allows per
    | client IP in each window. Set a window to 0 to disable it.
    |
    */
    'limits' => [
        'hourly' => 3000, // Max requests per hour
        'minute' => 60,   // Max requests per minute
        'second' => 10,   // Max requests per second
    ],

    /*
    |--------------------------------------------------------------------------
    | Whitelisted IPs
    |--------------------------------------------------------------------------
    |
    | Addresses exempt from the middleware. Entries may be single addresses or
    | CIDR ranges, in IPv4 or IPv6 form (e.g. '10.0.0.0/8', '::1').
    |
    */
    'whitelisted_ips' => [
        '127.0.0.1',
        '::1',
        '10.0.0.0/8',
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Headers
    |--------------------------------------------------------------------------
    |
    | When true, the middleware adds X-Rate-Limit-Limit and X-Rate-Limit-Remaining
    | to responses, reflecting the tightest configured window.
    |
    */
    'headers' => false,

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | When true, RateLimitException writes its own line to the log. When false,
    | the exception is handed back to the application's own reporters instead.
    |
    */
    'log_errors' => true,

    /*
    |--------------------------------------------------------------------------
    | IP Block Duration
    |--------------------------------------------------------------------------
    |
    | Default duration, in seconds, applied by blockIpAddress() when no explicit
    | duration is given.
    |
    */
    'block_seconds' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | Counters are stored in Redis. Set `connection` to the name of a connection
    | in config/database.php to inherit its host, port and credentials; anything
    | set explicitly below overrides it. `prefix` namespaces every key the
    | package writes and is applied by the package, not by ext-redis.
    |
    */
    'redis' => [
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
