<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\RateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    public function test_constructor_with_config(): void
    {
        $config = include __DIR__ . '/../config/rate-limiter.php';
        $rateLimiter = new RateLimiter($config);

        $this->assertInstanceOf(RateLimiter::class, $rateLimiter);
    }

    public function test_default_config_values(): void
    {
        $config = include __DIR__ . '/../config/rate-limiter.php';
        $rateLimiter = new RateLimiter($config);

        $this->assertEquals(3000, $rateLimiter->getConfigValue('limits.hourly'));
        $this->assertEquals(60, $rateLimiter->getConfigValue('limits.minute'));
        $this->assertEquals(10, $rateLimiter->getConfigValue('limits.second'));
    }

    public function test_custom_config_values(): void
    {
        $config = include __DIR__ . '/../config/rate-limiter.php';
        $config['hourly_request_limit'] = 5000;
        $config['minute_request_limit'] = 100;
        $config['second_request_limit'] = 5;

        $rateLimiter = new RateLimiter($config);

        $this->assertEquals(5000, $rateLimiter->getConfigValue('hourly_request_limit'));
        $this->assertEquals(100, $rateLimiter->getConfigValue('minute_request_limit'));
        $this->assertEquals(5, $rateLimiter->getConfigValue('second_request_limit'));
    }

    public function test_count_increments_correctly(): void
    {
        $config = include __DIR__ . '/../config/rate-limiter.php';
        $rateLimiter = new RateLimiter($config);
        $request = Request::create('/');

        $rateLimiter->create($request)->withName('requests:count')->withTimeInterval(10)->record(1);
        $rateLimiter->create($request)->withName('requests:count')->withTimeInterval(10)->record(1);
        $rateLimiter->create($request)->withName('requests:count')->withTimeInterval(10)->record(1);
        $rateLimiter->create($request)->withName('requests:count')->withTimeInterval(10)->record(1);
        $rateLimiter->create($request)->withName('requests:count')->withTimeInterval(10)->record(1);

        $count = $rateLimiter->create($request)->withName('requests:count')->withTimeInterval(10)->count();
        $this->assertEquals(5, $count);
    }

    public function test_limit_throws_exception_when_exceeded(): void
    {
        $config = include __DIR__ . '/../config/rate-limiter.php';
        $rateLimiter = new RateLimiter($config);
        $request = Request::create('/');

        $rateLimiter->create($request)->withName('requests:limit')->withTimeInterval(10)->record(1);
        $rateLimiter->create($request)->withName('requests:limit')->withTimeInterval(10)->record(1);
        $rateLimiter->create($request)->withName('requests:limit')->withTimeInterval(10)->record(1);
        $rateLimiter->create($request)->withName('requests:limit')->withTimeInterval(10)->record(1);
        $rateLimiter->create($request)->withName('requests:limit')->withTimeInterval(10)->record(1);

        $this->expectException(RateLimitException::class);
        $rateLimiter->create($request)->withName('requests:limit')->withTimeInterval(10)->limit(2);
    }

    public function test_limit_returns_count_when_not_exceeded(): void
    {
        $config = include __DIR__ . '/../config/rate-limiter.php';
        $rateLimiter = new RateLimiter($config);
        $request = Request::create('/');

        $count = $rateLimiter->create($request)->withName('requests:not_reached')->withTimeInterval(10)->limit(10);
        $this->assertEquals(1, $count);
    }

    public function test_request_tag_generation_with_user_id_and_name(): void
    {
        $config = include __DIR__ . '/../config/rate-limiter.php';
        $rateLimiter = new RateLimiter($config);
        $request = Request::create('/');

        $rateLimiter->create($request)->withUserId('100')->withName('request_name');
        $this->assertEquals('100:request_name:', $rateLimiter->getRequestTag());
    }

    public function test_set_request_tag_updates_tag(): void
    {
        $config = include __DIR__ . '/../config/rate-limiter.php';
        $rateLimiter = new RateLimiter($config);

        $rateLimiter->setRequestTag('request:set:');
        $this->assertEquals('request:set:', $rateLimiter->getRequestTag());
    }
}
