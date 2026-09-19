<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Illuminate\Http\Request;

class BlockIPAddressTest extends TestCase
{
    public function test_blocked_ip_is_detected_and_throws(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']);
        $limiter = $this->limiter();

        $limiter->blockIpAddress('203.0.113.7', 10);

        $this->assertTrue($limiter->create($request)->isIpAddressBlocked());

        $this->expectException(RateLimitException::class);
        $limiter->create($request)->checkIpAddress();
    }

    public function test_unblock_lifts_the_block(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.8']);
        $limiter = $this->limiter();

        $limiter->blockIpAddress('203.0.113.8', 60);
        $this->assertTrue($limiter->create($request)->isIpAddressBlocked());

        $limiter->unblockIpAddress('203.0.113.8');
        $this->assertFalse($limiter->create($request)->isIpAddressBlocked());
    }

    public function test_ipv6_blocks_cover_the_whole_64_prefix(): void
    {
        $limiter = $this->limiter();
        $limiter->blockIpAddress('2001:db8:9:9:aaaa::1', 60);

        $neighbour = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '2001:db8:9:9:ffff::2']);
        $stranger = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '2001:db8:9:10::2']);

        $this->assertTrue($limiter->create($neighbour)->isIpAddressBlocked());
        $this->assertFalse($limiter->create($stranger)->isIpAddressBlocked());
    }

    public function test_unknown_ip_is_never_blocked(): void
    {
        $this->assertFalse($this->limiter()->isIpAddressBlocked());
    }
}
