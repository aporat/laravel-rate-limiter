<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase;

class RateLimiterTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \Aporat\RateLimiter\Laravel\RateLimiterServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('rate-limiter.redis.host', '127.0.0.1');
        Config::set('rate-limiter.redis.port', 6379);
        Config::set('rate-limiter.redis.database', 15);
        Config::set('rate-limiter.redis.prefix', 'rate-limiter:test:');
        Config::set('rate-limiter.log_errors', false);
    }

    protected function tearDown(): void
    {
        $redis = new \Redis;
        $redis->connect('127.0.0.1', 6379);
        $redis->select(15);
        foreach ($redis->keys('rate-limiter:test:*') as $key) {
            $redis->del($key);
        }

        parent::tearDown();
    }

    public function test_clear_resets_counter()
    {
        $request = Request::create('/clear', 'GET');
        $limiter = new RateLimiter(Config::get('rate-limiter'));
        $limiter->create($request)->withClientIpAddress()->withTimeInterval(60);
        $limiter->record(3);

        $this->assertGreaterThan(0, $limiter->count());

        $limiter->clear();
        $this->assertSame(0, $limiter->count());
    }

    public function test_block_ip_address_sets_redis_key()
    {
        $request = Request::create('/block', 'GET');
        $limiter = new RateLimiter(Config::get('rate-limiter'));
        $limiter->create($request);
        $limiter->blockIpAddress($request->getClientIp(), 60);

        $this->assertTrue($limiter->isIpAddressBlocked());
    }
}
