<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\RateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{

    public function testConstructorWithArguments()
    {
        $config = include __DIR__ . '/../src/Config/rate-limiter.php';
        $rate_limiter = new RateLimiter($config);

        $this->assertInstanceOf('Aporat\RateLimiter\RateLimiter', $rate_limiter);
    }
    public function testDefaultSettings()
    {
        $config = include __DIR__ . '/../src/Config/rate-limiter.php';
        $rate_limiter = new RateLimiter($config);

        $this->assertEquals(3000, $rate_limiter->getConfigValue('hourly_request_limit'));
        $this->assertEquals(60, $rate_limiter->getConfigValue('minute_request_limit'));
        $this->assertEquals(10, $rate_limiter->getConfigValue('second_request_limit'));
    }


    public function testCustomSettings()
    {
        $config = include __DIR__ . '/../src/Config/rate-limiter.php';
        $config['hourly_request_limit'] = 5000;
        $config['minute_request_limit'] = 100;
        $config['second_request_limit'] = 5;

        $rate_limiter = new RateLimiter($config);

        $this->assertEquals(5000, $rate_limiter->getConfigValue('hourly_request_limit'));
        $this->assertEquals(100, $rate_limiter->getConfigValue('minute_request_limit'));
        $this->assertEquals(5, $rate_limiter->getConfigValue('second_request_limit'));
    }

    public function testCount() {
        $config = include __DIR__ . '/../src/Config/rate-limiter.php';
        $rate_limiter = new RateLimiter($config);
        $request = Request::create('/');

        $rate_limiter->create($request)->withName('requests:count')->withTimeInternal(10)->record(1);
        $rate_limiter->create($request)->withName('requests:count')->withTimeInternal(10)->record(1);
        $rate_limiter->create($request)->withName('requests:count')->withTimeInternal(10)->record(1);
        $rate_limiter->create($request)->withName('requests:count')->withTimeInternal(10)->record(1);
        $rate_limiter->create($request)->withName('requests:count')->withTimeInternal(10)->record(1);

        $limit = $rate_limiter->create($request)->withName('requests:count')->withTimeInternal(10)->count();
        $this->assertEquals(5, $limit);
    }

    public function testLimit() {
        $config = include __DIR__ . '/../src/Config/rate-limiter.php';
        $rate_limiter = new RateLimiter($config);
        $request = Request::create('/');

        $rate_limiter->create($request)->withName('requests:limit')->withTimeInternal(10)->record(1);
        $rate_limiter->create($request)->withName('requests:limit')->withTimeInternal(10)->record(1);
        $rate_limiter->create($request)->withName('requests:limit')->withTimeInternal(10)->record(1);
        $rate_limiter->create($request)->withName('requests:limit')->withTimeInternal(10)->record(1);
        $rate_limiter->create($request)->withName('requests:limit')->withTimeInternal(10)->record(1);

        $this->expectException(RateLimitException::class);
        $rate_limiter->create($request)->withName('requests:limit')->withTimeInternal(10)->limit(2);
    }

    public function testLimitNotReached() {
        $config = include __DIR__ . '/../src/Config/rate-limiter.php';
        $rate_limiter = new RateLimiter($config);
        $request = Request::create('/');

        $limit = $rate_limiter->create($request)->withName('requests:not_reached')->withTimeInternal(10)->limit(10);
        $this->assertEquals(1, $limit);
    }

    public function testRequestTagGeneration() {

        $config = include __DIR__ . '/../src/Config/rate-limiter.php';
        $rate_limiter = new RateLimiter($config);
        $request = Request::create('/');

        $rate_limiter->create($request)->withUserId('100')->withName('request_name');
        $this->assertEquals('100:request_name:', $rate_limiter->getRequestTag());
    }

    public function testSetTag() {

        $config = include __DIR__ . '/../src/Config/rate-limiter.php';
        $rate_limiter = new RateLimiter($config);

        $rate_limiter->setRequestTag('request:set:');
        $this->assertEquals('request:set:', $rate_limiter->getRequestTag());
    }
}
