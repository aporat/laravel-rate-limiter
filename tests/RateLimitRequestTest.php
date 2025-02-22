<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\RateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class RateLimitRequestTest extends TestCase
{
    public function test_limit_exceeds_with_request_info(): void
    {
        $config = include __DIR__.'/../config/rate-limiter.php';
        $rateLimiter = new RateLimiter($config);
        $request = Request::create('/test', 'POST');

        $this->expectException(RateLimitException::class);
        $rateLimiter->create($request)->withRequestInfo()->withTimeInterval(10)->limit(1);
        $rateLimiter->create($request)->withRequestInfo()->withTimeInterval(10)->limit(1);
    }

    public function test_limit_generates_request_tag_by_method_and_path(): void
    {
        $config = include __DIR__.'/../config/rate-limiter.php';
        $rateLimiter = new RateLimiter($config);

        $request = Request::create('/test3', 'POST');
        $limit = $rateLimiter->create($request)->withRequestInfo()->withTimeInterval(10)->limit(1);
        $this->assertEquals('POST:test3:', $rateLimiter->getRequestTag());
        $this->assertEquals(1, $limit);

        $request = Request::create('/test2', 'GET');
        $limit = $rateLimiter->create($request)->withRequestInfo()->withTimeInterval(10)->limit(1);
        $this->assertEquals('GET:test2:', $rateLimiter->getRequestTag());
        $this->assertEquals(1, $limit);
    }
}
