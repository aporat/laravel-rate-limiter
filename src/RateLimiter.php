<?php

declare(strict_types=1);

namespace Aporat\RateLimiter;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;
use Redis;
use RedisException;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiter for requests and actions using Redis as the storage backend.
 *
 * This class provides a fluent interface to configure and enforce rate limits based
 * on IP addresses, user IDs, request details, and custom tags, with optional response headers.
 *
 * Keys are namespaced in PHP rather than through Redis::OPT_PREFIX so that pattern
 * scans and deletes work on the same names the rest of the class builds.
 */
class RateLimiter
{
    /** Default window length, in seconds, when none is set explicitly. */
    public const int DEFAULT_INTERVAL = 3600;

    /** @var string Unique tag for the current rate limit context */
    protected string $requestTag = '';

    /** @var bool Whether to include rate limit headers in the response */
    protected bool $rateLimitHeaders = false;

    /** @var int Time interval in seconds for the rate limit window */
    protected int $intervalSeconds = self::DEFAULT_INTERVAL;

    /** @var Request|null The current HTTP request */
    protected ?Request $request = null;

    /** @var Response|null The response to modify with headers */
    protected ?Response $response = null;

    /** @var array<string, mixed> Configuration options from config/rate-limiter.php */
    protected array $config;

    /** @var Redis|null Redis client instance */
    protected ?Redis $redisClient = null;

    /**
     * Create a new RateLimiter instance.
     *
     * @param  array<string, mixed>  $config  Configuration array from rate-limiter.php
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Get a configuration value by key.
     *
     * Values are read from the live `rate-limiter` config repository when one is
     * bound, so a runtime `Config::set()` is picked up by the long-lived singleton.
     * The array passed to the constructor is the fallback, and the only source when
     * the limiter is used outside a Laravel application.
     *
     * @param  string  $key  Configuration key (e.g., 'limits.hourly')
     * @param  mixed  $default  Value returned when the key is absent
     * @return mixed The config value or $default if not found
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        $fallback = Arr::get($this->config, $key, $default);

        $repository = Container::getInstance()->bound('config')
            ? Container::getInstance()->make('config')
            : null;

        return $repository instanceof Repository
            ? $repository->get("rate-limiter.{$key}", $fallback)
            : $fallback;
    }

    /**
     * Set the request tag for rate limiting.
     *
     * @param  string  $requestTag  Custom tag for the request
     */
    public function setRequestTag(string $requestTag = ''): self
    {
        $this->requestTag = $requestTag;

        return $this;
    }

    /**
     * Get the current request tag.
     *
     * This is the logical tag, without the Redis key namespace.
     */
    public function getRequestTag(): string
    {
        return $this->requestTag;
    }

    /**
     * Append a name to the request tag for specific action limiting.
     *
     * @param  string  $name  Unique action name
     */
    public function withName(string $name): self
    {
        $this->requestTag .= $name.':';

        return $this;
    }

    /**
     * Initialize the limiter with a request.
     *
     * Resets any tag, response and window left over from a previous use, so the
     * shared singleton can be reused safely across calls.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function create(Request $request): self
    {
        $this->resetRequest();
        $this->request = $request;

        return $this;
    }

    /**
     * Limit requests by client IP address.
     *
     * @throws LogicException If no request has been set via create()
     */
    public function withClientIpAddress(): self
    {
        $this->requestTag .= $this->groupClientIp($this->requireRequest()->getClientIp()).':';

        return $this;
    }

    /**
     * Limit requests by method and path info.
     *
     * @throws LogicException If no request has been set via create()
     */
    public function withRequestInfo(): self
    {
        $request = $this->requireRequest();
        $this->requestTag .= $request->getMethod().str_replace('/', ':', $request->getPathInfo()).':';

        return $this;
    }

    /**
     * Limit requests by user ID.
     *
     * @param  string  $userId  User identifier
     */
    public function withUserId(string $userId): self
    {
        $this->requestTag .= $userId.':';

        return $this;
    }

    /**
     * Set the time interval for rate limiting.
     *
     * @param  int  $interval  Time interval in seconds (default: 3600)
     *
     * @throws InvalidArgumentException If the interval is not positive
     */
    public function withTimeInterval(int $interval = self::DEFAULT_INTERVAL): self
    {
        if ($interval < 1) {
            throw new InvalidArgumentException('Rate limit interval must be at least 1 second.');
        }

        $this->intervalSeconds = $interval;

        return $this;
    }

    /**
     * Attach a response object for header modification.
     *
     * @param  Response  $response  The HTTP response
     */
    public function withResponse(Response $response): self
    {
        $this->response = $response;

        return $this;
    }

    /**
     * Enable or disable rate limit headers in the response.
     *
     * @param  bool  $setHeaders  Whether to set headers (default: true)
     */
    public function withRateLimitHeaders(bool $setHeaders = true): self
    {
        $this->rateLimitHeaders = $setHeaders;

        return $this;
    }

    /**
     * Get the current request count for the tag.
     *
     * @throws RedisException If Redis operation fails
     */
    public function count(): int
    {
        if ($this->requestTag === '') {
            return 0;
        }

        return (int) $this->getRedisClient()->get($this->key($this->requestTag));
    }

    /**
     * Get the number of seconds left in the current window.
     *
     * @return int Seconds remaining, or 0 when the counter has no window
     *
     * @throws RedisException If Redis operation fails
     */
    public function ttl(): int
    {
        if ($this->requestTag === '') {
            return 0;
        }

        $ttl = $this->getRedisClient()->ttl($this->key($this->requestTag));

        return is_int($ttl) && $ttl > 0 ? $ttl : 0;
    }

    /**
     * Apply rate limiting and return the current count.
     *
     * @param  int  $limit  Maximum allowed requests
     * @param  int  $amount  Number of attempts to record
     * @return int Current request count
     *
     * @throws RateLimitException If limit is exceeded
     * @throws RedisException If Redis operation fails
     */
    public function limit(int $limit = 5000, int $amount = 1): int
    {
        if ($this->requestTag === '') {
            return 0;
        }

        $count = $this->record($amount);

        if ($count > $limit) {
            throw new RateLimitException(
                'Rate limit exceeded. Please try again later.',
                $this->request,
                ['tag' => $this->requestTag, 'limit' => $limit, 'count' => $count],
                retryAfter: $this->ttl() ?: $this->intervalSeconds,
            );
        }

        if ($this->rateLimitHeaders && $this->response instanceof Response) {
            $this->setHeaders($this->response, $limit, $limit - $count);
        }

        return $count;
    }

    /**
     * Record a number of attempts and return the current count.
     *
     * The increment and the window are applied in a single atomic script: the TTL is
     * (re)applied whenever the key has none, so a counter can never be left to live
     * forever because the process died between INCRBY and EXPIRE.
     *
     * @param  int  $amount  Number of attempts to record
     * @return int Current request count
     *
     * @throws RedisException If Redis operation fails
     */
    public function record(int $amount = 1): int
    {
        if ($this->requestTag === '') {
            return 0;
        }

        $script = <<<'LUA'
        local count = redis.call('INCRBY', KEYS[1], ARGV[1])
        if redis.call('TTL', KEYS[1]) < 0 then
            redis.call('EXPIRE', KEYS[1], ARGV[2])
        end
        return count
        LUA;

        return (int) $this->getRedisClient()->eval(
            $script,
            [$this->key($this->requestTag), $amount, $this->intervalSeconds],
            1
        );
    }

    /**
     * Clear the rate limit counter for the current tag.
     *
     * @throws RedisException If Redis operation fails
     */
    public function clear(): void
    {
        if ($this->requestTag === '') {
            return;
        }

        $this->getRedisClient()->del($this->key($this->requestTag));
    }

    /**
     * Determine whether an IP address is exempt from rate limiting.
     *
     * Entries in `rate-limiter.whitelisted_ips` may be plain addresses or CIDR
     * ranges, in either IPv4 or IPv6 form.
     *
     * @param  string|null  $ipAddress  IP address to test, or null for the current request
     */
    public function isWhitelisted(?string $ipAddress = null): bool
    {
        $ipAddress ??= $this->request?->getClientIp();

        if ($ipAddress === null || $ipAddress === '') {
            return false;
        }

        /** @var array<int, string> $whitelist */
        $whitelist = (array) $this->getConfigValue('whitelisted_ips', []);

        return $whitelist !== [] && IpUtils::checkIp($ipAddress, array_values($whitelist));
    }

    /**
     * Block an IP address for a specified duration.
     *
     * @param  string  $ipAddress  IP address to block
     * @param  int|null  $secondsToBlock  Duration in seconds (defaults to config, then 24 hours)
     *
     * @throws RedisException If Redis operation fails
     */
    public function blockIpAddress(string $ipAddress, ?int $secondsToBlock = null): void
    {
        $ipAddress = $this->groupClientIp($ipAddress);
        if ($ipAddress === null || $ipAddress === '') {
            return;
        }

        $seconds = max(1, $secondsToBlock ?? (int) $this->getConfigValue('block_seconds', 86400));

        $this->getRedisClient()->setex($this->blockKey($ipAddress), $seconds, 'blocked');
    }

    /**
     * Lift a block previously placed on an IP address.
     *
     * @param  string  $ipAddress  IP address to unblock
     *
     * @throws RedisException If Redis operation fails
     */
    public function unblockIpAddress(string $ipAddress): void
    {
        $ipAddress = $this->groupClientIp($ipAddress);
        if ($ipAddress === null || $ipAddress === '') {
            return;
        }

        $this->getRedisClient()->del($this->blockKey($ipAddress));
    }

    /**
     * Check if the current IP address is blocked.
     *
     * @return bool True if blocked, false otherwise
     *
     * @throws RedisException If Redis operation fails
     */
    public function isIpAddressBlocked(): bool
    {
        $ipAddress = $this->groupClientIp($this->request?->getClientIp());
        if ($ipAddress === null || $ipAddress === '') {
            return false;
        }

        return $this->getRedisClient()->exists($this->blockKey($ipAddress)) > 0;
    }

    /**
     * Throw an exception if the current IP is blocked.
     *
     * @throws RateLimitException If IP is blocked
     * @throws RedisException If Redis operation fails
     */
    public function checkIpAddress(): void
    {
        if ($this->isIpAddressBlocked()) {
            throw new RateLimitException(
                'IP address blocked due to rate limit violation.',
                $this->request,
                ['ip_address' => $this->request?->getClientIp()]
            );
        }
    }

    /**
     * Flush all rate limiter keys (use with caution, debugging only).
     *
     * @throws RedisException If Redis operation fails
     */
    public function flushAll(): void
    {
        $this->flushByLookup('*');
    }

    /**
     * Set rate limit headers on the response.
     *
     * @param  Response  $response  Response to modify
     * @param  int  $totalLimit  Total limit
     * @param  int  $remainingLimit  Remaining requests allowed
     * @return Response Modified response
     */
    protected function setHeaders(Response $response, int $totalLimit, int $remainingLimit): Response
    {
        $response->headers->set('X-Rate-Limit-Limit', (string) $totalLimit);
        $response->headers->set('X-Rate-Limit-Remaining', (string) max(0, $remainingLimit));

        return $response;
    }

    /**
     * Reset request-specific properties to their default state.
     */
    protected function resetRequest(): void
    {
        $this->requestTag = '';
        $this->request = null;
        $this->response = null;
        $this->intervalSeconds = self::DEFAULT_INTERVAL;
        $this->rateLimitHeaders = false;
    }

    /**
     * Get the request set by create(), failing loudly when there is none.
     *
     * @throws LogicException If no request has been set
     */
    protected function requireRequest(): Request
    {
        if (! $this->request instanceof Request) {
            throw new LogicException('No request set on the rate limiter; call create($request) first.');
        }

        return $this->request;
    }

    /**
     * Build the namespaced Redis key for a tag.
     */
    protected function key(string $tag): string
    {
        return $this->prefix().$tag;
    }

    /**
     * Build the namespaced Redis key holding an IP block.
     */
    protected function blockKey(string $ipAddress): string
    {
        return $this->key("blocked:ip:{$ipAddress}");
    }

    /**
     * The key namespace, normalised to exactly one trailing colon.
     */
    protected function prefix(): string
    {
        return rtrim((string) $this->getConfigValue('redis.prefix', 'rate-limiter'), ':').':';
    }

    /**
     * Group IPv6 addresses by /64 prefix for consistency in rate limiting.
     * IPv4 addresses are returned unchanged.
     *
     * @param  string|null  $ipAddress  IP address to process
     * @return string|null Processed IP address (IPv6 grouped by /64 prefix)
     */
    protected function groupClientIp(?string $ipAddress): ?string
    {
        if ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Take the first 8 bytes of the 16-byte address and zero-fill the rest (/64).
            $binary = @inet_pton($ipAddress);
            if ($binary !== false) {
                $grouped = @inet_ntop(str_pad(substr($binary, 0, 8), 16, "\0"));
                if ($grouped !== false) {
                    return $grouped;
                }
            }
        }

        return $ipAddress;
    }

    /**
     * Get or initialize the Redis client.
     *
     * When `redis.connection` names a connection in `config/database.php`, its
     * host/port/credentials are used as the base and the rate-limiter's own keys
     * override anything set explicitly here.
     *
     * @return Redis Configured Redis client
     *
     * @throws RedisException If connection fails
     */
    protected function getRedisClient(): Redis
    {
        if ($this->redisClient instanceof Redis) {
            return $this->redisClient;
        }

        $config = $this->redisConnectionConfig();

        $client = new Redis;
        $client->connect(
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 6379),
            (float) ($config['timeout'] ?? 2.0),
            null,
            0,
            (float) ($config['read_timeout'] ?? 2.0),
        );

        $password = $config['password'] ?? null;
        if (is_string($password) && $password !== '') {
            $username = $config['username'] ?? null;
            $client->auth(is_string($username) && $username !== '' ? [$username, $password] : $password);
        }

        $client->select((int) ($config['database'] ?? 0));

        return $this->redisClient = $client;
    }

    /**
     * Resolve the connection settings, merging any named `database.redis` connection
     * under the package's own `redis` block.
     *
     * @return array<string, mixed>
     */
    protected function redisConnectionConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->getConfigValue('redis', []);

        $connection = $config['connection'] ?? null;
        if (is_string($connection) && $connection !== '' && function_exists('config')) {
            /** @var array<string, mixed> $base */
            $base = (array) config("database.redis.{$connection}", []);
            $config = array_merge($base, array_filter($config, static fn ($value) => $value !== null && $value !== ''));
        }

        return $config;
    }

    /**
     * Flush Redis keys matching a lookup pattern within the package namespace.
     *
     * Uses SCAN rather than KEYS so a large keyspace does not block the server.
     *
     * @param  string  $lookup  Pattern to match keys, relative to the prefix (e.g., '*')
     *
     * @throws RedisException If Redis operation fails
     */
    protected function flushByLookup(string $lookup): void
    {
        $client = $this->getRedisClient();
        $pattern = $this->prefix().$lookup;
        $cursor = null;

        do {
            $keys = $client->scan($cursor, $pattern, 1000);

            if (is_array($keys) && $keys !== []) {
                $client->del($keys);
            }
        } while ($cursor > 0);
    }
}
