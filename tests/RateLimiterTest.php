<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\RateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{


    public function testConstructorWithArguments()
    {
        $config = include __DIR__.'/../src/Config/rate_limiter.php';
        $rate_limiter = new RateLimiter($config);

        $this->assertInstanceOf('Aporat\RateLimiter\RateLimiter', $rate_limiter);
    }
    public function testDefaultSettings()
    {
        $config = include __DIR__.'/../src/Config/rate_limiter.php';
        $rate_limiter = new RateLimiter($config);

        $this->assertEquals(3000, $rate_limiter->getConfigValue('hourly_request_limit'));
        $this->assertEquals(60, $rate_limiter->getConfigValue('minute_request_limit'));
        $this->assertEquals(10, $rate_limiter->getConfigValue('second_request_limit'));
    }


    public function testCustomSettings()
    {
        $config = include __DIR__.'/../src/Config/rate_limiter.php';
        $config['hourly_request_limit'] = 5000;
        $config['minute_request_limit'] = 100;
        $config['second_request_limit'] = 5;

        $rate_limiter = new RateLimiter($config);

        $this->assertEquals(5000, $rate_limiter->getConfigValue('hourly_request_limit'));
        $this->assertEquals(100, $rate_limiter->getConfigValue('minute_request_limit'));
        $this->assertEquals(5, $rate_limiter->getConfigValue('second_request_limit'));
    }

    public function testLimitNotReached() {
        $config = include __DIR__.'/../src/Config/rate_limiter.php';
        $rate_limiter = new RateLimiter($config);

        $limit = $rate_limiter->withName('requests:not_reached')->withTimeInternal(10)->limit(10);
        $this->assertEquals(1, $limit);
    }

    public function testRequestTagGeneration() {

        $config = include __DIR__.'/../src/Config/rate_limiter.php';
        $rate_limiter = new RateLimiter($config);

        $rate_limiter->withUserId('100')->withName('request_name');
        $this->assertEquals('100:request_name:', $rate_limiter->getRequestTag());
    }

}
