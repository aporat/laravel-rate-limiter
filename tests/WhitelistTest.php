<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class WhitelistTest extends TestCase
{
    public function test_cidr_ranges_and_single_addresses_are_matched(): void
    {
        Config::set('rate-limiter.whitelisted_ips', ['10.0.0.0/8', '192.168.1.1', '2001:db8::/32']);
        $limiter = $this->limiter();

        $this->assertTrue($limiter->isWhitelisted('10.4.5.6'));
        $this->assertTrue($limiter->isWhitelisted('192.168.1.1'));
        $this->assertTrue($limiter->isWhitelisted('2001:db8:1::9'));
        $this->assertFalse($limiter->isWhitelisted('192.168.1.2'));
        $this->assertFalse($limiter->isWhitelisted('203.0.113.1'));
    }

    public function test_it_falls_back_to_the_current_request_ip(): void
    {
        Config::set('rate-limiter.whitelisted_ips', ['10.0.0.0/8']);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.1.1']);

        $this->assertTrue($this->limiter()->create($request)->isWhitelisted());
    }

    public function test_an_empty_whitelist_matches_nothing(): void
    {
        Config::set('rate-limiter.whitelisted_ips', []);

        $this->assertFalse($this->limiter()->isWhitelisted('10.0.1.1'));
    }
}
