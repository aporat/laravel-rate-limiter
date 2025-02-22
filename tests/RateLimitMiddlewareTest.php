<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Laravel\Middleware\RateLimit;
use Aporat\RateLimiter\Laravel\RateLimiterServiceProvider;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

class RateLimitMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(RateLimiterServiceProvider::class);
    }

    public function test_exempts_internal_ip(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.1.1']);
        $middleware = new RateLimit();
        $response = $middleware->handle($request, fn ($req) => response('OK'));

        $this->assertEquals('OK', $response->getContent());
    }
}
