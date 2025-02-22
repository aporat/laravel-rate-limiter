<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\RateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class BlockIPAddressTest extends TestCase
{
    public function test_block_ip_address_triggers_exception(): void
    {
        $config = include __DIR__.'/../config/rate-limiter.php';
        $rateLimiter = new RateLimiter($config);
        $request = Request::create('/');

        $rateLimiter->blockIpAddress($request->getClientIp(), 10);

        $this->expectException(RateLimitException::class);
        $rateLimiter->create($request)->checkIpAddress();
    }
}
