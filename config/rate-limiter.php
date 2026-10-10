<?php

/**
 * Configuration for aporat/laravel-rate-limiter.
 *
 * @see https://github.com/aporat/laravel-rate-limiter
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Redis connection
    |--------------------------------------------------------------------------
    |
    | The name of a connection in config/database.php ('redis' section). Any
    | client Laravel supports works: phpredis or predis, single node or cluster,
    | TLS, REDIS_URL. Give the limiter its own connection with short `timeout`
    | and `read_timeout` values so a slow Redis cannot stall requests.
    |
    */
    'connection' => env('RATE_LIMITER_REDIS_CONNECTION', 'default'),

    /*
    | Namespace for every key the package writes, on top of the connection's
    | own `options.prefix`.
    */
    'prefix' => env('RATE_LIMITER_PREFIX', 'rate-limiter'),

    /*
    |--------------------------------------------------------------------------
    | Middleware defaults
    |--------------------------------------------------------------------------
    |
    | Requests allowed per window for the `rate.limiter` middleware when the
    | route passes no window parameters. Windows: second, minute, hourly, daily.
    | Set a window to 0 to disable it. `key` is what requests are counted by:
    | ip, user (authenticated id, falling back to the IP), route, or a
    | combination joined with '+', e.g. 'user+route'.
    |
    */
    'limits' => [
        'hourly' => 3000,
        'minute' => 60,
        'second' => 10,
    ],

    'key' => 'ip',

    /*
    |--------------------------------------------------------------------------
    | Whitelisted IPs
    |--------------------------------------------------------------------------
    |
    | Addresses or CIDR ranges that skip the middleware. Only loopback by
    | default: do NOT add private ranges such as 10.0.0.0/8 when the app runs
    | behind a load balancer or proxy. Without trusted proxies configured,
    | every request appears to come from the proxy (so all of them would be
    | whitelisted); with trusted proxies set to '*', any client can claim a
    | private address in X-Forwarded-For.
    |
    */
    'whitelisted_ips' => [
        '127.0.0.1/32',
        '::1/128',
    ],

    /*
    | Add X-RateLimit-Limit, X-RateLimit-Remaining and X-RateLimit-Reset to
    | responses that pass the middleware (429 responses always carry them,
    | plus Retry-After).
    */
    'headers' => true,

    /*
    | When true, each 429 writes one warning line (method, path, client IP,
    | retry-after). Query strings, input and headers are never logged.
    */
    'log_errors' => false,

    /*
    | Default duration, in seconds, for blockIpAddress().
    */
    'block_seconds' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Redis failures
    |--------------------------------------------------------------------------
    |
    | 'open'   - let requests through unchecked while Redis is unavailable.
    | 'closed' - refuse them with a 503 Service Unavailable + Retry-After.
    |
    | After a failure, Redis is not contacted again for `failure_backoff_seconds`
    | (per PHP process), so requests do not each wait for a connect timeout. The
    | Redis error is passed to report() once per backoff period.
    |
    */
    'on_redis_failure' => env('RATE_LIMITER_ON_REDIS_FAILURE', 'open'),

    'failure_backoff_seconds' => 5,
];
