<?php

declare(strict_types=1);

namespace Aporat\RateLimiter;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Redis;
use RedisException;

/**
 * Rate limiter for requests and actions using Redis as the storage backend.
 *
 * This class provides a fluent interface to configure and enforce rate limits based
 * on IP addresses, user IDs, request details, and custom tags, with optional response headers.
 */
class RateLimiter
{
    /** @var string Unique tag for the current rate limit context */
    protected string $requestTag = '';

    /** @var bool Whether to include rate limit headers in the response */
    protected bool $rateLimitHeaders = false;

    /** @var int Time interval in seconds for the rate limit window */
    protected int $intervalSeconds = 0;

    /** @var Request|null The current HTTP request */
    protected ?Request $request = null;

    /** @var Response|null The response to modify with headers */
    protected ?Response $response = null;

    /** @var array Configuration options from config/rate-limiter.php */
    protected array $config;

    /** @var Redis|null Redis client instance */
    protected ?Redis $redisClient = null;

    /** @var string[] Headers to inspect for client IP */
    protected array $headersToInspect = ['X-Forwarded-For'];

    /**
     * Create a new RateLimiter instance.
     *
     * @param array<string, mixed> $config Configuration array from rate-limiter.php
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Get a configuration value by key.
     *
     * @param string $key Configuration key (e.g., 'limits.hourly')
     * @return mixed The config value or null if not found
     */
    public function getConfigValue(string $key): mixed
    {
        return Arr::get($this->config, $key);
    }

    /**
     * Set the request tag for rate limiting.
     *
     * @param string $requestTag Custom tag for the request
     * @return self
     */
    public function setRequestTag(string $requestTag = ''): self
    {
        $this->requestTag = $requestTag;
        return $this;
    }

    /**
     * Get the current request tag.
     */
    public function getRequestTag(): string
    {
        return $this->requestTag;
    }

    /**
     * Append a name to the request tag for specific action limiting.
     *
     * @param string $name Unique action name
     * @return self
     */
    public function withName(string $name): self
    {
        $this->requestTag .= $name . ':';
        return $this;
    }

    /**
     * Initialize the limiter with a request.
     *
     * @param Request $request The incoming HTTP request
     * @return self
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
     * @return self
     */
    public function withClientIpAddress(): self
    {
        $this->requestTag .= $this->groupClientIp($this->request->getClientIp()) . ':';
        return $this;
    }

    /**
     * Limit requests by method and path info.
     *
     * @return self
     */
    public function withRequestInfo(): self
    {
        $this->requestTag .= $this->request->getMethod() . str_replace('/', ':', $this->request->getPathInfo()) . ':';
        return $this;
    }

    /**
     * Limit requests by user ID.
     *
     * @param string $userId User identifier
     * @return self
     */
    public function withUserId(string $userId): self
    {
        $this->requestTag .= $userId . ':';
        return $this;
    }

    /**
     * Set the time interval for rate limiting.
     *
     * @param int $interval Time interval in seconds (default: 3600)
     * @return self
     */
    public function withTimeInterval(int $interval = 3600): self
    {
        $this->intervalSeconds = $interval;
        return $this;
    }

    /**
     * Attach a response object for header modification.
     *
     * @param Response $response The HTTP response
     * @return self
     */
    public function withResponse(Response $response): self
    {
        $this->response = $response;
        return $this;
    }

    /**
     * Enable or disable rate limit headers in the response.
     *
     * @param bool $setHeaders Whether to set headers (default: true)
     * @return self
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
        return (int) $this->getRedisClient()->get($this->requestTag) ?: 0;
    }

    /**
     * Apply rate limiting and return the current count.
     *
     * @param int $limit Maximum allowed requests
     * @param int $amount Number of attempts to record
     * @return int Current request count
     * @throws RateLimitException If limit is exceeded
     * @throws RedisException If Redis operation fails
     */
    public function limit(int $limit = 5000, int $amount = 1): int
    {
        if (empty($this->requestTag)) {
            return 0;
        }

        $count = $this->record($amount);

        if ($count > $limit) {
            $debugInfo = ['tag' => $this->requestTag, 'limit' => $limit, 'count' => $count];
            throw new RateLimitException('Rate limit exceeded. Please try again later.', $this->request, $debugInfo);
        }

        if ($this->rateLimitHeaders && $this->response) {
            $this->setHeaders($this->response, $limit, $limit - $count);
        }

        return $count;
    }

    /**
     * Record a number of attempts and return the current count.
     *
     * @param int $amount Number of attempts to record
     * @return int Current request count
     * @throws RedisException If Redis operation fails
     */
    public function record(int $amount = 1): int
    {
        $count = $this->getRedisClient()->incrBy($this->requestTag, $amount);

        if ($count === $amount) {
            $this->getRedisClient()->expireAt($this->requestTag, time() + $this->intervalSeconds);
        }

        return $count;
    }

    /**
     * Clear the rate limit counter for the current tag.
     *
     * @throws RedisException If Redis operation fails
     */
    public function clear(): void
    {
        $this->getRedisClient()->del([$this->requestTag]);
    }

    /**
     * Block an IP address for a specified duration.
     *
     * @param string $ipAddress IP address to block
     * @param int $secondsToBlock Duration in seconds (default: 24 hours)
     * @throws RedisException If Redis operation fails
     */
    public function blockIpAddress(string $ipAddress, int $secondsToBlock = 86400): void
    {
        $ipAddress = $this->groupClientIp($ipAddress);
        if (empty($ipAddress)) {
            return;
        }

        $tag = "blocked:ip:{$ipAddress}";
        $this->getRedisClient()->set($tag, 'blocked');
        $this->getRedisClient()->expireAt($tag, time() + $secondsToBlock);
    }

    /**
     * Check if the current IP address is blocked.
     *
     * @return bool True if blocked, false otherwise
     * @throws RedisException If Redis operation fails
     */
    public function isIpAddressBlocked(): bool
    {
        $ipAddress = $this->request?->getClientIp();
        if (!$ipAddress) {
            return false;
        }

        $tag = "blocked:ip:{$ipAddress}";
        return $this->getRedisClient()->get($tag) === 'blocked';
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
     * Set rate limit headers on the response.
     *
     * @param Response $response Response to modify
     * @param int $totalLimit Total limit
     * @param int $remainingLimit Remaining requests allowed
     * @return Response Modified response
     */
    protected function setHeaders(Response $response, int $totalLimit, int $remainingLimit): Response
    {
        return $response->withHeaders([
            'X-Rate-Limit-Limit' => (string) $totalLimit,
            'X-Rate-Limit-Remaining' => (string) $remainingLimit,
        ]);
    }

    /**
     * Reset request-specific properties to their default state.
     */
    protected function resetRequest(): void
    {
        $this->requestTag = '';
        $this->request = null;
        $this->response = null;
        $this->intervalSeconds = 0;
        $this->rateLimitHeaders = false;
    }

    /**
     * Group IPv6 addresses for consistency in rate limiting.
     *
     * @param string|null $ipAddress IP address to process
     * @return string|null Processed IP address
     */
    protected function groupClientIp(?string $ipAddress): ?string
    {
        if ($ipAddress && str_contains($ipAddress, '::') && substr_count($ipAddress, ':') === 4) {
            return Str::substr($ipAddress, 0, 9);
        }
        return $ipAddress;
    }

    /**
     * Get or initialize the Redis client.
     *
     * @return Redis Configured Redis client
     * @throws RedisException If connection fails
     */
    protected function getRedisClient(): Redis
    {
        if (!$this->redisClient) {
            $redisConfig = Arr::get($this->config, 'redis', []);
            $this->redisClient = new Redis();
            $this->redisClient->connect($redisConfig['host'] ?? '127.0.0.1', $redisConfig['port'] ?? 6379);
            $this->redisClient->select((int) ($redisConfig['database'] ?? 0));
            $this->redisClient->setOption(Redis::OPT_PREFIX, ($redisConfig['prefix'] ?? 'rate-limiter') . ':');
        }
        return $this->redisClient;
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
     * Flush Redis keys matching a lookup pattern.
     *
     * @param string $lookup Pattern to match keys (e.g., '*')
     * @throws RedisException If Redis operation fails
     */
    protected function flushByLookup(string $lookup): void
    {
        $prefix = Arr::get($this->config, 'redis.prefix', 'rate-limiter') . ':';
        $keys = $this->getRedisClient()->keys($lookup);

        if (empty($keys)) {
            return;
        }

        $strippedKeys = array_map(fn (string $key) => Str::after($key, $prefix), $keys);
        $this->getRedisClient()->del($strippedKeys);
    }
}
