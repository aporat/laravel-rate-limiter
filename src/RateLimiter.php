<?php

declare(strict_types=1);

namespace Aporat\RateLimiter;

use Aporat\RateLimiter\Events\IpBlocked;
use Aporat\RateLimiter\Exceptions\RateLimiterUnavailableException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Redis\Connections\PredisConnection;
use InvalidArgumentException;
use LogicException;
use Predis\ClientInterface;
use Predis\Command\Processor\KeyPrefixProcessor;
use Predis\PredisException;
use Predis\Response\ErrorInterface as PredisError;
use Predis\Response\ServerException as PredisServerException;
use Redis;
use RedisCluster;
use RedisClusterException;
use RedisException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Redis-backed fixed-window rate limiter.
 *
 * The instance registered in the container is long-lived (Octane-safe): it holds
 * no request state. Config and the Redis connection are resolved from the current
 * container on every call, and each limit is described by a fresh, immutable
 * {@see PendingLimit} returned from {@see create()} and friends.
 *
 * The only state kept here is the process-wide Redis failure backoff, which is
 * static on purpose so it survives Octane's per-request container sandboxes.
 */
class RateLimiter
{
    /** Default window length, in seconds, when none is set explicitly. */
    public const int DEFAULT_INTERVAL = 3600;

    /**
     * Checks every window, then counts the attempt in all of them only if all allow it.
     *
     * KEYS: one counter per window (they share a hash tag, so one cluster slot).
     * ARGV[1]: amount; then per window: limit (-1 = unlimited), window length in ms.
     * Returns: {allowed, then per window: count, ms until reset, exceeded}.
     */
    public const string SCRIPT = <<<'LUA'
        local amount = tonumber(ARGV[1])
        local n = #KEYS
        local counts, ttls, exceeded = {}, {}, {}
        local allowed = 1
        for i = 1, n do
            local limit = tonumber(ARGV[i * 2])
            local current = tonumber(redis.call('GET', KEYS[i]) or '0')
            counts[i] = current
            ttls[i] = redis.call('PTTL', KEYS[i])
            exceeded[i] = 0
            if limit >= 0 and current + amount > limit then
                allowed = 0
                exceeded[i] = 1
            end
        end
        if allowed == 1 then
            for i = 1, n do
                counts[i] = redis.call('INCRBY', KEYS[i], amount)
                local ttl = redis.call('PTTL', KEYS[i])
                if ttl < 0 then
                    ttl = tonumber(ARGV[i * 2 + 1])
                    redis.call('PEXPIRE', KEYS[i], ttl)
                end
                ttls[i] = ttl
            end
        end
        local out = {allowed}
        for i = 1, n do
            if ttls[i] < 0 then
                ttls[i] = tonumber(ARGV[i * 2 + 1])
            end
            out[#out + 1] = counts[i]
            out[#out + 1] = ttls[i]
            out[#out + 1] = exceeded[i]
        end
        return out
        LUA;

    /** Unix time (float) until which Redis is considered unavailable, per process. */
    protected static float $unavailableUntil = 0.0;

    /**
     * @param  Config|null  $config  Fixed config repository (standalone use); null to resolve it from the container on every call
     * @param  RedisFactory|null  $redis  Fixed Redis factory (standalone use); null to resolve it from the container on every call
     */
    public function __construct(
        private readonly ?Config $config = null,
        private readonly ?RedisFactory $redis = null,
    ) {}

    /**
     * Start describing a limit, optionally for a request.
     */
    public function create(?Request $request = null): PendingLimit
    {
        return new PendingLimit($this, $request);
    }

    /**
     * Alias of {@see create()}.
     */
    public function for(?Request $request = null): PendingLimit
    {
        return $this->create($request);
    }

    /**
     * Start a limit keyed on a user identifier.
     */
    public function withUserId(string|int $userId): PendingLimit
    {
        return $this->create()->withUserId($userId);
    }

    /**
     * Start a limit keyed on a custom name.
     */
    public function withName(string $name): PendingLimit
    {
        return $this->create()->withName($name);
    }

    /**
     * Read a value from the `rate-limiter` config.
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->config()->get("rate-limiter.{$key}", $default);
    }

    /**
     * Check every window and, only if all of them allow it, count the attempt in all of them.
     *
     * @param  list<string>  $segments  Key segments identifying the subject (escaped here)
     * @param  array<int, int>  $windows  Window length in seconds => limit (-1 for unlimited)
     *
     * @throws RateLimiterUnavailableException When Redis is unavailable and `on_redis_failure` is `closed`
     */
    public function attempt(array $segments, array $windows, int $amount = 1): LimitResult
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('The amount to record must be at least 1.');
        }

        if ($windows === []) {
            return new LimitResult(true, []);
        }

        $keys = [];
        $arguments = [$amount];

        foreach ($windows as $seconds => $limit) {
            if ($seconds < 1) {
                throw new InvalidArgumentException('Rate limit windows must be at least 1 second long.');
            }

            $keys[] = $this->windowKey($segments, $seconds);
            $arguments[] = $limit;
            $arguments[] = $seconds * 1000;
        }

        $raw = $this->guard(fn (Connection $connection) => $this->runScript($connection, $keys, $arguments));

        if ($raw === null) {
            return LimitResult::degraded();
        }

        $states = [];
        $index = 1;

        foreach ($windows as $seconds => $limit) {
            $states[] = new WindowState(
                $seconds,
                $limit,
                (int) ($raw[$index] ?? 0),
                (int) ($raw[$index + 1] ?? $seconds * 1000),
                (int) ($raw[$index + 2] ?? 0) === 1,
            );
            $index += 3;
        }

        return new LimitResult((int) ($raw[0] ?? 0) === 1, $states);
    }

    /**
     * Current count of one window (0 when Redis is unavailable and failing open).
     *
     * @param  list<string>  $segments
     */
    public function countFor(array $segments, int $seconds): int
    {
        $key = $this->windowKey($segments, $seconds);

        return (int) $this->guard(fn (Connection $connection) => $connection->command('get', [$key]));
    }

    /**
     * Seconds left in one window, rounded up (0 when there is no live window).
     *
     * @param  list<string>  $segments
     */
    public function ttlFor(array $segments, int $seconds): int
    {
        $key = $this->windowKey($segments, $seconds);
        $ttl = (int) $this->guard(fn (Connection $connection) => $connection->command('pttl', [$key]));

        return $ttl > 0 ? (int) ceil($ttl / 1000) : 0;
    }

    /**
     * Delete one window's counter.
     *
     * @param  list<string>  $segments
     */
    public function clearFor(array $segments, int $seconds): void
    {
        $this->connection()->command('del', [$this->windowKey($segments, $seconds)]);
    }

    /**
     * Determine whether an IP address is exempt from rate limiting.
     *
     * Entries may be plain addresses or CIDR ranges, IPv4 or IPv6.
     */
    public function isWhitelisted(?string $ipAddress): bool
    {
        if ($ipAddress === null || $ipAddress === '') {
            return false;
        }

        $whitelist = array_values(array_filter(
            (array) $this->getConfigValue('whitelisted_ips', []),
            static fn ($entry) => is_string($entry) && $entry !== ''
        ));

        return $whitelist !== [] && IpUtils::checkIp($ipAddress, $whitelist);
    }

    /**
     * Block an IP address (IPv6 addresses are blocked by /64).
     *
     * Admin operations do not apply `on_redis_failure`: a Redis error is thrown.
     */
    public function blockIpAddress(string $ipAddress, ?int $secondsToBlock = null): void
    {
        $subject = $this->ipSubject($ipAddress);
        $seconds = $secondsToBlock ?? (int) $this->getConfigValue('block_seconds', 86400);

        if ($seconds < 1) {
            throw new InvalidArgumentException('An IP block must last at least 1 second.');
        }

        $this->connection()->command('setex', [$this->blockKey($subject), $seconds, '1']);

        $this->dispatch(new IpBlocked($ipAddress, $seconds));
    }

    /**
     * Lift a block placed on an IP address.
     */
    public function unblockIpAddress(string $ipAddress): void
    {
        $this->connection()->command('del', [$this->blockKey($this->ipSubject($ipAddress))]);
    }

    /**
     * Seconds left on an IP block, or 0 when the address is not blocked.
     */
    public function blockedFor(?string $ipAddress): int
    {
        if ($ipAddress === null || $ipAddress === '') {
            return 0;
        }

        $key = $this->blockKey($this->ipSubject($ipAddress));
        $ttl = (int) $this->guard(fn (Connection $connection) => $connection->command('pttl', [$key]));

        return $ttl > 0 ? (int) ceil($ttl / 1000) : 0;
    }

    /**
     * Determine whether an IP address is currently blocked.
     */
    public function isIpAddressBlocked(?string $ipAddress): bool
    {
        return $this->blockedFor($ipAddress) > 0;
    }

    /**
     * Delete every key in the package namespace (single-node Redis only).
     *
     * @throws LogicException On a Redis cluster connection
     */
    public function flushAll(): void
    {
        $connection = $this->connection();

        if ($connection instanceof PhpRedisClusterConnection || $connection instanceof PredisClusterConnection) {
            throw new LogicException('flushAll() is not supported on a Redis cluster connection.');
        }

        $client = $connection->client();

        if ($connection instanceof PhpRedisConnection && $client instanceof Redis) {
            $connectionPrefix = (string) $client->getOption(Redis::OPT_PREFIX);
            $pattern = $connectionPrefix.$this->prefix().'*';
            $iterator = null;

            do {
                $keys = $client->scan($iterator, $pattern, 1000);

                if (is_array($keys) && $keys !== []) {
                    $client->del(array_map(fn (string $key) => $this->stripPrefix($key, $connectionPrefix), $keys));
                }
            } while ($iterator > 0);

            return;
        }

        if ($connection instanceof PredisConnection && $client instanceof ClientInterface) {
            $processor = $client->getOptions()->prefix;
            $connectionPrefix = $processor instanceof KeyPrefixProcessor ? $processor->getPrefix() : '';
            $cursor = '0';

            do {
                /** @var array{0: string, 1: list<string>} $result */
                $result = $client->scan($cursor, ['match' => $connectionPrefix.$this->prefix().'*', 'count' => 1000]);
                [$cursor, $keys] = $result;

                if ($keys !== []) {
                    $client->del(array_map(fn (string $key) => $this->stripPrefix($key, $connectionPrefix), $keys));
                }
            } while ((string) $cursor !== '0');

            return;
        }

        throw new LogicException('flushAll() supports the phpredis and predis clients only.');
    }

    /**
     * The Redis key for one window of a subject.
     *
     * Segments are percent-encoded, so no segment can contain the `:`, `{` or `}`
     * used to build keys, and all windows of one subject share the `{...}` hash
     * tag, so a Redis cluster keeps them in one slot (required by the script).
     *
     * @param  list<string>  $segments
     */
    public function windowKey(array $segments, int $seconds): string
    {
        return $this->prefix().'{'.$this->subject($segments).'}:'.$seconds.'s';
    }

    /**
     * The Redis key holding an IP block.
     */
    public function blockKey(string $subject): string
    {
        return $this->prefix().'blocked:{'.rawurlencode($subject).'}';
    }

    /**
     * The client IP as used for keys: IPv6 addresses are grouped by their /64.
     */
    public function ipSubject(string $ipAddress): string
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binary = inet_pton($ipAddress);

            if ($binary !== false) {
                $grouped = inet_ntop(str_pad(substr($binary, 0, 8), 16, "\0"));

                if ($grouped !== false) {
                    return $grouped.'/64';
                }
            }
        }

        return $ipAddress;
    }

    /**
     * Forget the process-wide Redis failure backoff (for tests).
     */
    public static function flushState(): void
    {
        static::$unavailableUntil = 0.0;
    }

    /**
     * Run a Redis operation, applying `on_redis_failure` when Redis is unavailable.
     *
     * Returns null when failing open. While a failure backoff is active, Redis is
     * not contacted at all, so a dead server does not cost every request a timeout.
     *
     * @template T
     *
     * @param  callable(Connection): T  $operation
     * @return T|null
     *
     * @throws RateLimiterUnavailableException
     */
    protected function guard(callable $operation): mixed
    {
        if (microtime(true) < static::$unavailableUntil) {
            return $this->failed();
        }

        try {
            return $operation($this->connection());
        } catch (Throwable $e) {
            if (! $this->isRedisFailure($e)) {
                throw $e;
            }

            $backoff = max(0, (int) $this->getConfigValue('failure_backoff_seconds', 5));
            static::$unavailableUntil = microtime(true) + $backoff;

            // Reported once per backoff period: further calls skip Redis until it ends.
            if (function_exists('report')) {
                report($e);
            } else {
                error_log('Rate limiter Redis failure: '.$e->getMessage());
            }

            return $this->failed();
        }
    }

    /**
     * @throws RateLimiterUnavailableException
     */
    protected function failed(): null
    {
        if ($this->getConfigValue('on_redis_failure', 'open') === 'closed') {
            throw new RateLimiterUnavailableException(
                max(1, (int) ceil(static::$unavailableUntil - microtime(true)))
            );
        }

        return null;
    }

    protected function isRedisFailure(Throwable $e): bool
    {
        return $e instanceof RedisException
            || $e instanceof RedisClusterException
            || $e instanceof PredisException
            || $e instanceof RedisScriptFailure;
    }

    /**
     * Run the limiter script with EVALSHA, falling back to EVAL on NOSCRIPT.
     *
     * @param  list<string>  $keys
     * @param  list<int>  $arguments
     * @return array<int, mixed>
     */
    protected function runScript(Connection $connection, array $keys, array $arguments): array
    {
        $sha = sha1(self::SCRIPT);
        $phpredis = $connection instanceof PhpRedisConnection;
        $parameters = [...$keys, ...$arguments];

        try {
            $result = $phpredis
                ? $connection->command('evalsha', [$sha, $parameters, count($keys)])
                : $connection->command('evalsha', [$sha, count($keys), ...$parameters]);
        } catch (PredisServerException $e) {
            if (! str_starts_with($e->getMessage(), 'NOSCRIPT')) {
                throw $e;
            }

            $result = null;
        }

        if ($result === false && $phpredis) {
            $client = $connection->client();
            $error = $client instanceof Redis || $client instanceof RedisCluster ? (string) $client->getLastError() : '';

            if (! str_starts_with($error, 'NOSCRIPT')) {
                throw new RedisScriptFailure($error !== '' ? $error : 'The rate limiter script failed.');
            }

            $client->clearLastError();
            $result = null;
        }

        if ($result instanceof PredisError) {
            if (! str_starts_with($result->getMessage(), 'NOSCRIPT')) {
                throw new RedisScriptFailure($result->getMessage());
            }

            $result = null;
        }

        // EVAL also caches the script, so the next EVALSHA finds it.
        $result ??= $phpredis
            ? $connection->command('eval', [self::SCRIPT, $parameters, count($keys)])
            : $connection->command('eval', [self::SCRIPT, count($keys), ...$parameters]);

        if ($result === false && $phpredis) {
            $client = $connection->client();
            $error = $client instanceof Redis || $client instanceof RedisCluster ? (string) $client->getLastError() : '';

            throw new RedisScriptFailure($error !== '' ? $error : 'The rate limiter script failed.');
        }

        if (! is_array($result)) {
            throw new RedisScriptFailure('The rate limiter script returned an unexpected result.');
        }

        return $result;
    }

    protected function connection(): Connection
    {
        $name = $this->getConfigValue('connection');
        $factory = $this->redis ?? Container::getInstance()->make('redis');

        if (! $factory instanceof RedisFactory) {
            throw new LogicException('No Redis factory is bound in the container.');
        }

        $connection = $factory->connection(is_string($name) && $name !== '' ? $name : null);

        if (! $connection instanceof Connection) {
            throw new LogicException('The rate limiter needs an Illuminate\Redis connection.');
        }

        return $connection;
    }

    protected function config(): Config
    {
        if ($this->config instanceof Config) {
            return $this->config;
        }

        $config = Container::getInstance()->make('config');

        if (! $config instanceof Config) {
            throw new LogicException('No config repository is bound in the container.');
        }

        return $config;
    }

    /**
     * Dispatch a package event when an event dispatcher is bound.
     *
     * @internal
     */
    public function dispatch(object $event): void
    {
        $container = Container::getInstance();

        if ($container->bound('events')) {
            $events = $container->make('events');

            if ($events instanceof Dispatcher) {
                $events->dispatch($event);
            }
        }
    }

    /**
     * The package key namespace, with exactly one trailing colon.
     */
    protected function prefix(): string
    {
        return rtrim((string) $this->getConfigValue('prefix', 'rate-limiter'), ':').':';
    }

    /**
     * @param  list<string>  $segments
     */
    protected function subject(array $segments): string
    {
        if ($segments === []) {
            throw new LogicException('A rate limit needs at least one key segment (IP, user, name or route).');
        }

        return implode(':', array_map(rawurlencode(...), $segments));
    }

    private function stripPrefix(string $key, string $prefix): string
    {
        return $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
    }
}
