<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\RateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class BlockIPAddressTest extends TestCase
{
    public function testBlockIPAddress()
    {
        $config = include __DIR__.'/../src/config/rate-limiter.php';
        $rate_limiter = new RateLimiter($config);
        $request = Request::create('/');

        $rate_limiter->blockIpAddress($request->getClientIp(), 10);

        $this->expectException(RateLimitException::class);
        $rate_limiter->create($request)->checkIpAddress();
    }
}
