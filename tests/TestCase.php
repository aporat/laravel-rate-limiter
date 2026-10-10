<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\RateLimiter;
use Aporat\RateLimiter\RateLimiterServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Redis\Connections\Connection;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Redis database used by the suite (flushed of package keys around every test). */
    public const int DATABASE = 15;

    protected function getPackageProviders($app): array
    {
        return [RateLimiterServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');

        $config->set('database.redis.client', self::redisClient());
        $config->set('database.redis.options.prefix', 'lrltest_');
        $config->set('database.redis.default', [
            'host' => self::redisHost(),
            'port' => self::redisPort(),
            'database' => self::DATABASE,
        ]);
        $config->set('rate-limiter.prefix', 'rl');
        $config->set('rate-limiter.log_errors', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::flushState();
        $this->limiter()->flushAll();
    }

    protected function tearDown(): void
    {
        RateLimiter::flushState();
        TrustProxies::flushState();
        $this->limiter()->flushAll();

        parent::tearDown();
    }

    protected function limiter(): RateLimiter
    {
        $limiter = $this->app?->make(RateLimiter::class);
        $this->assertInstanceOf(RateLimiter::class, $limiter);

        return $limiter;
    }

    /**
     * Switch the suite's Redis client for the rest of the test.
     */
    protected function useRedisClient(string $client): void
    {
        $this->appConfig()->set('database.redis.client', $client);
        $this->app?->forgetInstance('redis');
        $this->app?->forgetInstance('redis.connection');
    }

    protected function appConfig(): Repository
    {
        $config = $this->app?->make('config');
        $this->assertInstanceOf(Repository::class, $config);

        return $config;
    }

    protected function redisConnection(): Connection
    {
        $redis = $this->app?->make('redis');
        $this->assertInstanceOf(Factory::class, $redis);
        $connection = $redis->connection();
        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    protected function rawRedis(): \Redis
    {
        $redis = new \Redis;
        $redis->connect(self::redisHost(), self::redisPort());
        $redis->select(self::DATABASE);

        return $redis;
    }

    public static function redisClient(): string
    {
        $client = getenv('REDIS_CLIENT');

        return is_string($client) && $client !== '' ? $client : 'phpredis';
    }

    public static function redisHost(): string
    {
        $host = getenv('REDIS_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    public static function redisPort(): int
    {
        $port = getenv('REDIS_PORT');

        return is_string($port) && ctype_digit($port) ? (int) $port : 6379;
    }
}
