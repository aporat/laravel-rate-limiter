<?php

namespace Aporat\RateLimiter;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Exception;
use Redis;
use Aporat\RateLimiter\Exceptions\RateLimitException;

/**
 * Class Middleware
 *
 * @package RateLimiter
 *
 */
final class RateLimiter
{

    /**
     * @var string request tag
     */
    protected string $request_tag = "";

    /**
     * @var bool should response contain limit headers
     */
    protected bool $rate_limit_headers = false;

    /**
     * @var int seconds for rate limit the action
     */
    protected int $interval_seconds = 0;

    /**
     * @var Request|null $request
     */
    protected ?Request $request = null;

    /**
     * @var Response|null $response
     */
    protected ?Response $response = null;

    /**
     * @var array config options
     */
    protected array $config = [];

    /**
     * @var Redis|null redis client
     */
    protected ?Redis $redis_client = null;

    /**
     * List of proxy headers inspected for the client IP address
     *
     * @var array
     */
    protected array $headersToInspect = [
        'X-Forwarded-For'
    ];

    /**
     *  Create a new rate limiter instance.
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getConfigValue(string $key): mixed
    {
        return Arr::get($this->config, $key);
    }

    /**
     * @param string $request_tag
     * @return $this
     */
    public function setRequestTag(string $request_tag = ''): self
    {
        $this->request_tag = $request_tag;
        return $this;
    }

    /**
     * @return string request tag
     */
    public function getRequestTag(): string
    {
        return $this->request_tag;
    }

    /**
     * Limit by action name
     *
     * @param string $name unique name of action to rate limit
     *
     * @return self
     */
    public function withName(string $name): self
    {
        $this->request_tag .= $name . ':';

        return $this;
    }

    /**
     * Set a PSR-7 Request
     *
     * @param Request $request PSR-7 Request
     *
     * @return self
     */
    public function create(Request $request): self
    {
        $this->resetRequest();
        $this->request = $request;

        return $this;
    }

    /**
     * Limit by ip address
     *
     * @return self
     */
    public function withClientIpAddress(): self
    {
        $ip_address = $this->groupClientIp($this->request->getClientIp());

        $this->request_tag .= $ip_address . ':';

        return $this;
    }

    /**
     * Limit by request info
     *
     * @return self
     */
    public function withRequestInfo(): self
    {
        $this->request_tag .= $this->request->getMethod() . str_replace('/', ':', $this->request->getPathInfo()) . ':';

        return $this;
    }

    /**
     * limit by user id
     *
     * @param string $user_id
     *
     * @return self
     */
    public function withUserId(string $user_id): self
    {
        $this->request_tag .= $user_id . ':';

        return $this;
    }

    /**
     * @param int $interval time internal, in seconds
     *
     * @return self
     */
    public function withTimeInternal(int $interval = 3600): self
    {
        $this->interval_seconds = $interval;

        return $this;
    }

    /**
     * set a PSR-7 response
     *
     * @param Response $response
     *
     * @return self
     */
    public function withResponse(Response $response): self
    {
        $this->response = $response;

        return $this;
    }

    /**
     * Set rate limit headers
     *
     * @param boolean $set_headers should rate limit headers be appended to response
     *
     * @return self
     */
    public function withRateLimitHeaders(bool $set_headers = true): self
    {
        $this->rate_limit_headers = $set_headers;

        return $this;
    }

    /**
     **
     * @return int
     */
    public function count(): int
    {
        $actions_count = 0;

        try {
            $actions_count = (int)$this->getRedisClient()->get($this->request_tag);
        } catch (Exception $e) {
            //
        }

        return $actions_count;
    }

    /**
     * Rate limit a specific action
     *
     * @param int $limit hourly limit
     * @param int $amount amount of actions executed
     *
     * @return int
     * @throws RateLimitException
     */
    public function limit(int $limit = 5000, int $amount = 1): int
    {
        $action_count = 0;

        if (!empty($this->request_tag)) {
            $action_count = $this->record($amount);

            if ($action_count > $limit) {
                throw new RateLimitException('Rate limit exceeded. Please try again later.', $this->request, ['tag' => $this->request_tag, 'limit' => $limit , 'action_count' => $action_count]);
            }
        }

        return $action_count;
    }

    /**
     * Record the action count to storage
     *
     * @param int $amount action amount
     *
     * @return int
     */
    public function record($amount = 1): int
    {
        $actions_count = 0;

        try {
            $actions_count = $this->getRedisClient()->incrby($this->request_tag, $amount);
        } catch (Exception $e) {
            //
        }

        // Must be their first visit so let's set the expiration time.
        if ($amount > 0 && $actions_count == $amount) {
            $this->getRedisClient()->expireat($this->request_tag, time() + $this->interval_seconds);
        }

        return $actions_count;
    }

    /**
     ** clear the request tag
     */
    public function clear(): void
    {

        try {
            $this->getRedisClient()->del($this->request_tag);
        } catch (Exception $e) {
            //
        }
    }

    /**
     * Rate limit a specific action
     *
     * @param array $actions
     * @param int $limit hourly limit
     *
     * @return int
     * @throws RateLimitException
     */
    public function limitActions(array $actions, int $limit = 5000): int
    {
        $actions_count = 0;

        if (!empty($this->request_tag)) {
            $this->recordActions($actions);
            $actions_count = $this->countActions();

            if ($actions_count > $limit) {
                throw new RateLimitException('Rate limit exceeded. Please try again later.', $this->request, ['tag' => $this->request_tag, 'limit' => $limit , 'action_count' => $actions_count]);
            }
        }

        return $actions_count;
    }

    /**
     * @param array $actions
     * @return int
     */
    public function recordActions(array $actions): int
    {
        $actions_count = 0;

        try {
            $actions_count = $this->getRedisClient()->sadd($this->request_tag, $actions);
        } catch (Exception $e) {
            //
        }

        $this->getRedisClient()->expireat($this->request_tag, time() + $this->interval_seconds);

        return $actions_count;
    }

    /**
     **
     * @return int
     */
    public function countActions(): int
    {
        $actions_count = 0;

        try {
            $actions_count = count($this->getRedisClient()->smembers($this->request_tag));
        } catch (Exception $e) {
            //
        }

        return $actions_count;
    }

    /**
     * @param string $ip_address
     * @param int $seconds_to_block
     */
    public function blockIpAddress(string $ip_address, int $seconds_to_block = 60 * 60 * 24): void
    {
        $ip_address = $this->groupClientIp($ip_address);

        $tag = 'blocked:ip:' . $ip_address;

        if (!empty($ip_address)) {
            $this->getRedisClient()->set($tag, 'blocked');
            $this->getRedisClient()->expireat($tag, time() + $seconds_to_block);
        }
    }


    /**
     * @return bool
     */
    public function isIpAddressBlocked(): bool
    {
        $ip_address = $this->request->getClientIp();

        $tag = 'blocked:ip:' . $ip_address;

        if ($this->getRedisClient()->get($tag) == 'blocked') {
            return true;
        }

        return false;
    }

    /**
     * @throws RateLimitException
     */
    public function checkIpAddress(): void
    {
        if ($this->isIpAddressBlocked()) {
            $ip_address = $this->request->getClientIp();

            throw new RateLimitException('Rate limit exceeded. Please try again later.', $this->request, ['ip_address' => $ip_address]);
        }
    }

    /**
     * Set the headers to the response
     *
     * @param Response $response
     * @param int $total_limit
     * @param int $remaining_limit
     *
     * @return Response
     */
    protected function setHeaders(Response $response, int $total_limit, int $remaining_limit): Response
    {
        $response = $response->header('X-Rate-Limit-Limit', (string)$total_limit);
        $response = $response->header('X-Rate-Limit-Remaining', (string)$remaining_limit);

        return $response;
    }

    /**
     * Resets the request limit options
     */
    protected function resetRequest(): void
    {
        $this->request_tag = '';
        $this->request = null;
        $this->response = null;
        $this->interval_seconds = 0;
        $this->rate_limit_headers = false;
    }

    /**
     * @param string|null $ip_address
     * @return string|null
     */
    protected function groupClientIp(?string $ip_address): ?string
    {
        // ipv6 should be grouped
        if ($ip_address != null && strpos($ip_address, '::') !== false && substr_count($ip_address, ':') == 4) {
            $ip_address = Str::substr($ip_address, 0, 9);
        }

        return $ip_address;
    }

    /**
     * Reset all limits. useful for debugging
     * not recommended for production use
     */
    public function flushAll(): void
    {
        $this->_flushByLookup("*");
    }

    /**
     * Flush redis by a specific lookup
     *
     * @param string $lookup lookup key
     *
     */
    protected function _flushByLookup(string $lookup): void
    {
        $keys = $this->getRedisClient()->keys($lookup);

        $keys_with_no_prefix = [];
        foreach ($keys as $key) {
            $key = Str::substr($key, strlen(Arr::get($this->config, 'redis.prefix')));
            $keys_with_no_prefix[] = $key;
        }

        if (count($keys_with_no_prefix) > 0) {
            $this->getRedisClient()->del($keys_with_no_prefix);
        }
    }

    /**
     * @return Redis
     */
    protected function getRedisClient(): Redis
    {

        if ($this->redis_client == null) {
            $redis_config = Arr::get($this->config, 'redis');

            $this->redis_client = new Redis();
            $this->redis_client->connect($redis_config['host'], $redis_config['port']);
            $this->redis_client->setOption(Redis::OPT_PREFIX, $redis_config['prefix'] . ':');
        }

        return $this->redis_client;
    }
}
