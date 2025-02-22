<?php
/**
 * Configuration for the Laravel Rate Limiter package.
 *
 * This file defines the rate limiting thresholds and storage settings for the
 * rate-limiter middleware.
 *
 * @see https://github.com/aporat/laravel-rate-limiter
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Thresholds
    |--------------------------------------------------------------------------
    |
    | Define the maximum number of requests allowed within each time window.
    | These limits can be applied per IP, user, or route depending on your middleware setup.
    |
    */
    'limits' => [
        'hourly'  => 3000, // Max requests per hour
        'minute'  => 60,   // Max requests per minute
        'second'  => 10,   // Max requests per second
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the Redis store used to track rate limit counters. Ensure your
    | Redis server is running and accessible with these credentials.
    |
    */
    'redis' => [
        'host'     => env('RATE_LIMITER_REDIS_HOST', '127.0.0.1'),
        'port'     => env('RATE_LIMITER_REDIS_PORT', 6379),
        'database' => env('RATE_LIMITER_REDIS_DB', 0),
        'prefix'   => env('RATE_LIMITER_REDIS_PREFIX', 'rate-limiter:'),
    ],
];
