<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\RateLimiter;
use Aporat\RateLimiter\RateLimiterServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RateLimiterServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app['config']->set('rate-limiter.redis.host', env('REDIS_HOST', '127.0.0.1'));
        $app['config']->set('rate-limiter.redis.port', (int) env('REDIS_PORT', 6379));
        $app['config']->set('rate-limiter.redis.database', 15);
        $app['config']->set('rate-limiter.redis.prefix', 'rate-limiter:test');
        $app['config']->set('rate-limiter.log_errors', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->limiter()->flushAll();
    }

    protected function tearDown(): void
    {
        $this->limiter()->flushAll();

        parent::tearDown();
    }

    protected function limiter(): RateLimiter
    {
        return $this->app->make(RateLimiter::class);
    }
}
