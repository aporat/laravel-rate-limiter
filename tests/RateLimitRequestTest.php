<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\RateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class RateLimitRequestTest extends TestCase
{

    public function testLimitRequestInfo() {

        $config = include __DIR__ . '/../src/Config/rate-limiter.php';
        $rate_limiter = new RateLimiter($config);
        $request = Request::create('/test', 'POST');

        $this->expectException(RateLimitException::class);
        $rate_limiter->create($request)->withRequestInfo()->withTimeInternal(10)->limit(1);
        $rate_limiter->create($request)->withRequestInfo()->withTimeInternal(10)->limit(1);

    }

    public function testMethodLimitRequestInfo() {

        $config = include __DIR__ . '/../src/Config/rate-limiter.php';
        $rate_limiter = new RateLimiter($config);

        $request = Request::create('/test3', 'POST');
        $limit = $rate_limiter->create($request)->withRequestInfo()->withTimeInternal(10)->limit(1);
        $this->assertEquals('POST:test3:', $rate_limiter->getRequestTag());
        $this->assertEquals(1, $limit);

        $request = Request::create('/test2', 'GET');
        $limit = $rate_limiter->create($request)->withRequestInfo()->withTimeInternal(10)->limit(1);
        $this->assertEquals('GET:test2:', $rate_limiter->getRequestTag());
        $this->assertEquals(1, $limit);
    }
}
