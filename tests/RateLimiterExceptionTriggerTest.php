<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase;
use Redis;

class RateLimiterExceptionTriggerTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('rate-limiter.redis.host', '127.0.0.1');
        $app['config']->set('rate-limiter.redis.port', 6379);
        $app['config']->set('rate-limiter.redis.database', 15); // use isolated DB
        $app['config']->set('rate-limiter.redis.prefix', 'test-rate-limiter');
        $app['config']->set('rate-limiter.log_errors', false);
    }

    protected function tearDown(): void
    {
        // Clean up Redis keys after test
        $redis = new \Redis;
        $redis->connect('127.0.0.1', 6379);
        $redis->select(15);
        foreach ($redis->keys('test-rate-limiter*') as $key) {
            $redis->del($key);
        }
        parent::tearDown();
    }

    public function test_limit_method_throws_exception_when_exceeded()
    {
        $request = Request::create('/test', 'GET');
        $rateLimiter = new RateLimiter(Config::get('rate-limiter'));
        $rateLimiter->create($request)
            ->withClientIpAddress()
            ->withTimeInterval(60);

        $this->expectException(RateLimitException::class);

        // Simulate hitting the limit
        for ($i = 0; $i <= 5; $i++) {
            $rateLimiter->limit(5);
        }
    }

    public function test_check_ip_address_throws_when_blocked()
    {
        $request = Request::create('/test', 'GET');
        $rateLimiter = new RateLimiter(Config::get('rate-limiter'));
        $rateLimiter->create($request);
        $rateLimiter->blockIpAddress($request->getClientIp(), 60);

        $this->expectException(RateLimitException::class);
        $rateLimiter->checkIpAddress();
    }
}
