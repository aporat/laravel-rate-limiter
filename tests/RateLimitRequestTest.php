<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase;

class RateLimitRequestTest extends TestCase
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
        Config::set('rate-limiter.limits.second', 100); // prevent accidental limit triggering

        // clean Redis
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
        $redis->select(15);
        foreach ($redis->keys('rate-limiter:test:*') as $key) {
            $redis->del($key);
        }
    }

    public function test_limit_generates_request_tag_by_method_and_path()
    {
        $request = Request::create('/test-path', 'POST');

        $limiter = new RateLimiter(Config::get('rate-limiter'));
        $limiter->create($request)
            ->withRequestInfo()
            ->withTimeInterval(60)
            ->limit(10); // don't exceed the limit

        $tag = $limiter->getRequestTag();
        $this->assertStringContainsString('POST:test-path', $tag);
    }
}
