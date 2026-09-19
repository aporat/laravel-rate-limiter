<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\Middleware\RateLimit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

class RateLimitMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('rate-limiter.limits.second', 2);
        Config::set('rate-limiter.limits.minute', 1000);
        Config::set('rate-limiter.limits.hourly', 10000);
        Config::set('rate-limiter.whitelisted_ips', ['10.0.0.0/8']);
        Config::set('rate-limiter.headers', false);
    }

    public function test_it_exempts_whitelisted_ips(): void
    {
        Config::set('rate-limiter.limits.second', 1);

        $middleware = $this->middleware();
        $request = $this->requestFrom('10.0.1.1');

        $middleware->handle($request, fn () => new Response('OK'));
        $response = $middleware->handle($request, fn () => new Response('OK'));

        $this->assertSame('OK', $response->getContent());
    }

    public function test_it_allows_requests_under_the_limit(): void
    {
        $response = $this->middleware()->handle(
            $this->requestFrom('203.0.113.10'),
            fn () => new Response('OK', 200)
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_it_blocks_requests_over_the_limit(): void
    {
        $middleware = $this->middleware();
        $request = $this->requestFrom('203.0.113.11');

        $middleware->handle($request, fn () => new Response('OK'));
        $middleware->handle($request, fn () => new Response('OK'));

        $this->expectException(RateLimitException::class);
        $middleware->handle($request, fn () => new Response('OK'));
    }

    public function test_each_window_gets_its_own_counter(): void
    {
        $middleware = $this->middleware();
        $middleware->handle($this->requestFrom('203.0.113.12'), fn () => new Response('OK'));

        $limiter = $this->limiter();

        foreach (['hourly' => 3600, 'minute' => 60, 'second' => 1] as $name => $interval) {
            $limiter->create($this->requestFrom('203.0.113.12'))
                ->withClientIpAddress()
                ->withName("requests:{$name}");

            $this->assertSame(
                '203.0.113.12:requests:'.$name.':',
                $limiter->getRequestTag(),
                "The {$name} window should not inherit another window's tag."
            );
            $this->assertSame(1, $limiter->count(), "The {$name} window should have its own counter.");
            $this->assertLessThanOrEqual($interval, $limiter->ttl());
        }
    }

    public function test_it_rejects_a_blocked_ip_before_counting(): void
    {
        $this->limiter()->blockIpAddress('203.0.113.13', 60);

        $this->expectException(RateLimitException::class);
        $this->middleware()->handle($this->requestFrom('203.0.113.13'), fn () => new Response('OK'));
    }

    public function test_it_adds_headers_when_enabled(): void
    {
        Config::set('rate-limiter.headers', true);

        $response = $this->middleware()->handle(
            $this->requestFrom('203.0.113.14'),
            fn () => new Response('OK')
        );

        $this->assertSame('2', $response->headers->get('X-Rate-Limit-Limit'));
        $this->assertSame('1', $response->headers->get('X-Rate-Limit-Remaining'));
    }

    public function test_windows_set_to_zero_are_skipped(): void
    {
        Config::set('rate-limiter.limits.second', 0);
        Config::set('rate-limiter.limits.minute', 0);
        Config::set('rate-limiter.limits.hourly', 0);

        $middleware = $this->middleware();
        $request = $this->requestFrom('203.0.113.15');

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('OK', $middleware->handle($request, fn () => new Response('OK'))->getContent());
        }
    }

    private function middleware(): RateLimit
    {
        return $this->app->make(RateLimit::class);
    }

    private function requestFrom(string $ip): Request
    {
        return Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    }
}
