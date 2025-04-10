<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\Laravel\Middleware\RateLimit;
use Aporat\RateLimiter\Laravel\RateLimiterServiceProvider;
use Aporat\RateLimiter\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase;

class RateLimitMiddlewareTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            RateLimiterServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('rate-limiter.limits.second', 1);
        Config::set('rate-limiter.limits.minute', 1000);
        Config::set('rate-limiter.limits.hourly', 10000);
        Config::set('rate-limiter.redis.database', 15);
        Config::set('rate-limiter.redis.prefix', 'rate-limiter:test:');

        // clear Redis
        $limiter = new RateLimiter(config('rate-limiter'));
        $limiter->flushAll();
    }

    public function test_exempts_internal_ip(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.1.1']);
        $middleware = new RateLimit;
        $response = $middleware->handle($request, fn ($req) => response('OK'));

        $this->assertEquals('OK', $response->getContent());
    }

    public function test_middleware_allows_request_under_limit()
    {
        $middleware = new RateLimit();
        $request = Request::create('/test', 'GET');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $response = $middleware->handle($request, fn () => new Response('OK', 200));
        $this->assertEquals(200, $response->status());
    }

    public function test_middleware_blocks_request_over_limit()
    {
        $middleware = new RateLimit();
        $request = Request::create('/test', 'GET');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $middleware->handle($request, fn () => new Response('OK'));
        $this->expectException(RateLimitException::class);
        $middleware->handle($request, fn () => new Response('Too Many'));
    }

}
