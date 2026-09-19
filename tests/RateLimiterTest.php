<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

class RateLimiterTest extends TestCase
{
    public function test_record_applies_the_window_and_counts_up(): void
    {
        $limiter = $this->limiter()->create(Request::create('/count'))
            ->withClientIpAddress()
            ->withTimeInterval(60);

        $this->assertSame(3, $limiter->record(3));
        $this->assertSame(4, $limiter->record());
        $this->assertSame(4, $limiter->count());
        $this->assertGreaterThan(0, $limiter->ttl());
        $this->assertLessThanOrEqual(60, $limiter->ttl());
    }

    public function test_record_reapplies_a_window_to_a_key_that_lost_its_ttl(): void
    {
        $limiter = $this->limiter()->create(Request::create('/orphan'))
            ->withName('orphan')
            ->withTimeInterval(60);

        $limiter->record();

        // Simulate the pre-Lua failure mode: the counter exists with no expiry.
        $redis = $this->rawRedis();
        $redis->persist('rate-limiter:test:orphan:');
        $this->assertSame(-1, $redis->ttl('rate-limiter:test:orphan:'));

        $limiter->record();

        $this->assertGreaterThan(0, $limiter->ttl());
    }

    public function test_clear_resets_counter(): void
    {
        $limiter = $this->limiter()->create(Request::create('/clear'))
            ->withClientIpAddress()
            ->withTimeInterval(60);

        $limiter->record(3);
        $this->assertSame(3, $limiter->count());

        $limiter->clear();
        $this->assertSame(0, $limiter->count());
    }

    public function test_limit_throws_once_the_threshold_is_passed(): void
    {
        $limiter = $this->limiter()->create(Request::create('/limit'))
            ->withClientIpAddress()
            ->withTimeInterval(60);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame($i, $limiter->limit(5));
        }

        try {
            $limiter->limit(5);
            $this->fail('Expected the limit to be exceeded.');
        } catch (RateLimitException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertNotNull($e->getRetryAfter());
            $this->assertSame(['Retry-After' => (string) $e->getRetryAfter()], $e->getHeaders());
        }
    }

    public function test_limit_is_a_no_op_without_a_tag(): void
    {
        $limiter = $this->limiter()->create(Request::create('/untagged'));

        $this->assertSame(0, $limiter->limit(1));
        $this->assertSame(0, $limiter->record());
        $this->assertSame(0, $limiter->count());
        $this->assertSame(0, $limiter->ttl());
    }

    public function test_request_tag_is_built_from_method_and_path(): void
    {
        $limiter = $this->limiter()->create(Request::create('/test-path', 'POST'))
            ->withRequestInfo()
            ->withTimeInterval(60);

        $limiter->limit(10);

        $this->assertSame('POST:test-path:', $limiter->getRequestTag());
    }

    public function test_create_resets_state_between_uses(): void
    {
        $limiter = $this->limiter();

        $limiter->create(Request::create('/first'))->withRequestInfo();
        $this->assertSame('GET:first:', $limiter->getRequestTag());

        $limiter->create(Request::create('/second'))->withRequestInfo();
        $this->assertSame('GET:second:', $limiter->getRequestTag());
    }

    public function test_tag_helpers_require_a_request(): void
    {
        $this->expectException(LogicException::class);

        (new RateLimiter($this->app['config']->get('rate-limiter')))->withClientIpAddress();
    }

    public function test_time_interval_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->limiter()->withTimeInterval(0);
    }

    public function test_headers_are_written_to_the_attached_response(): void
    {
        $response = new Response('OK');

        $this->limiter()->create(Request::create('/headers'))
            ->withRequestInfo()
            ->withTimeInterval(60)
            ->withResponse($response)
            ->withRateLimitHeaders()
            ->limit(10);

        $this->assertSame('10', $response->headers->get('X-Rate-Limit-Limit'));
        $this->assertSame('9', $response->headers->get('X-Rate-Limit-Remaining'));
    }

    public function test_ipv6_addresses_are_grouped_by_64_prefix(): void
    {
        $limiter = $this->limiter();

        $first = $limiter->create($this->requestFrom('2001:db8:1:2:aaaa:bbbb:cccc:dddd'))
            ->withClientIpAddress()->getRequestTag();
        $second = $limiter->create($this->requestFrom('2001:db8:1:2:1111:2222:3333:4444'))
            ->withClientIpAddress()->getRequestTag();
        $other = $limiter->create($this->requestFrom('2001:db8:1:3::1'))
            ->withClientIpAddress()->getRequestTag();

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $other);
    }

    public function test_flush_all_only_removes_keys_in_the_package_namespace(): void
    {
        $redis = $this->rawRedis();
        $redis->set('unrelated:key', 'keep');

        $this->limiter()->create(Request::create('/flush'))
            ->withRequestInfo()
            ->withTimeInterval(60)
            ->record();

        $this->limiter()->flushAll();

        $this->assertSame([], $redis->keys('rate-limiter:test:*'));
        $this->assertSame('keep', $redis->get('unrelated:key'));

        $redis->del('unrelated:key');
    }

    private function requestFrom(string $ip): Request
    {
        return Request::create('/ip', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    private function rawRedis(): \Redis
    {
        $redis = new \Redis;
        $redis->connect((string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));
        $redis->select(15);

        return $redis;
    }
}
