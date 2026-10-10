<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Events\IpBlocked;
use Aporat\RateLimiter\Events\RateLimitExceeded;
use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\Facades\RateLimiter as RateLimiterFacade;
use Aporat\RateLimiter\PendingLimit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class PendingLimitTest extends TestCase
{
    private function request(string $ip = '203.0.113.10', string $uri = '/x'): Request
    {
        return Request::create($uri, 'GET', server: ['REMOTE_ADDR' => $ip]);
    }

    public function test_record_counts_and_applies_the_window(): void
    {
        $limit = $this->limiter()->create($this->request())->withClientIpAddress()->withTimeInterval(60);

        $this->assertSame(3, $limit->record(3));
        $this->assertSame(4, $limit->record());
        $this->assertSame(4, $limit->count());
        $this->assertGreaterThan(0, $limit->ttl());
        $this->assertLessThanOrEqual(60, $limit->ttl());
    }

    public function test_clear_resets_the_counter(): void
    {
        $limit = $this->limiter()->withName('clear')->withTimeInterval(60);
        $limit->record(3);
        $limit->clear();

        $this->assertSame(0, $limit->count());
        $this->assertSame(0, $limit->ttl());
    }

    public function test_limit_throws_once_the_limit_is_reached_and_does_not_count_refusals(): void
    {
        $limit = $this->limiter()->withName('form')->withTimeInterval(60);

        $this->assertSame(1, $limit->limit(2)->windows[0]->count);
        $this->assertSame(2, $limit->limit(2)->windows[0]->count);

        for ($i = 0; $i < 3; $i++) {
            try {
                $limit->limit(2);
                $this->fail('Expected a RateLimitException.');
            } catch (RateLimitException $e) {
                $this->assertSame(429, $e->getStatusCode());
                $this->assertGreaterThanOrEqual(1, $e->getRetryAfter());
                $this->assertLessThanOrEqual(60, $e->getRetryAfter());
                $this->assertSame('0', $e->getHeaders()['X-RateLimit-Remaining']);
            }
        }

        $this->assertSame(2, $limit->count(), 'Refused attempts must not be counted.');
    }

    public function test_attempt_returns_the_result_without_throwing(): void
    {
        $limit = $this->limiter()->withName('attempt')->withTimeInterval(60);

        $this->assertTrue($limit->attempt(1)->allowed);
        $result = $limit->attempt(1);

        $this->assertFalse($result->allowed);
        $this->assertTrue($result->windows[0]->exceeded);
    }

    public function test_an_amount_larger_than_what_is_left_is_refused_whole(): void
    {
        $limit = $this->limiter()->withName('bulk')->withTimeInterval(60);
        $limit->limit(5, 4);

        $this->assertFalse($limit->attempt(5, 2)->allowed);
        $this->assertSame(4, $limit->count());
        $this->assertTrue($limit->attempt(5, 1)->allowed);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function invalidLimits(): array
    {
        return [
            'zero amount' => [5, 0],
            'negative amount' => [5, -100],
            'zero limit' => [0, 1],
            'negative limit' => [-1, 1],
        ];
    }

    #[DataProvider('invalidLimits')]
    public function test_it_rejects_non_positive_limits_and_amounts(int $limit, int $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->limiter()->withName('invalid')->limit($limit, $amount);
    }

    public function test_record_rejects_non_positive_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->limiter()->withName('invalid')->record(-5);
    }

    public function test_it_rejects_a_non_positive_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->limiter()->withName('x')->withTimeInterval(0);
    }

    public function test_a_limit_without_any_key_segment_is_refused(): void
    {
        $this->expectException(LogicException::class);

        $this->limiter()->create()->limit(5);
    }

    public function test_client_ip_needs_a_request_with_an_ip(): void
    {
        try {
            $this->limiter()->create()->withClientIpAddress();
            $this->fail('Expected a LogicException.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $this->limiter()->create($this->request(''))->withClientIpAddress();
    }

    public function test_builders_are_immutable_and_never_share_state(): void
    {
        $base = $this->limiter()->create($this->request())->withClientIpAddress();
        $hourly = $base->withTimeInterval(3600);
        $named = $base->withName('extra');

        $this->assertNotSame($base, $hourly);
        $this->assertSame(['ip', '203.0.113.10'], $base->segments());
        $this->assertSame(['ip', '203.0.113.10', 'name', 'extra'], $named->segments());
        $this->assertStringEndsWith(':3600s', $hourly->key());

        // The facade starts every call from scratch: nothing from a previous
        // builder (or the middleware) leaks into it.
        $this->assertSame(['user', 'u1'], RateLimiterFacade::withUserId('u1')->segments());
        $this->assertSame([], RateLimiterFacade::create()->segments());
        $this->assertNotSame(RateLimiterFacade::create(), RateLimiterFacade::create());
    }

    public function test_key_segments_cannot_collide(): void
    {
        $a = $this->limiter()->withUserId('a:b')->withTimeInterval(60);
        $b = $this->limiter()->withUserId('a')->withName('b')->withTimeInterval(60);
        $c = $this->limiter()->withName('x}:{y')->withTimeInterval(60);
        $d = $this->limiter()->withName('x')->withName('y')->withTimeInterval(60);

        $this->assertNotSame($a->key(), $b->key());
        $this->assertNotSame($c->key(), $d->key());

        $a->record();
        $this->assertSame(1, $b->record());
        $c->record();
        $this->assertSame(1, $d->record());
        $this->assertSame(1, substr_count($c->key(), '{'));
    }

    public function test_ipv6_clients_are_grouped_by_their_slash_64(): void
    {
        $one = $this->limiter()->create($this->request('2001:db8:1:2:aaaa::1'))->withClientIpAddress()->withTimeInterval(60);
        $two = $this->limiter()->create($this->request('2001:db8:1:2:bbbb::2'))->withClientIpAddress()->withTimeInterval(60);
        $other = $this->limiter()->create($this->request('2001:db8:1:3::1'))->withClientIpAddress()->withTimeInterval(60);

        $one->record();
        $this->assertSame(2, $two->record());
        $this->assertSame(1, $other->record());
        $this->assertSame(['ip', '2001:db8:1:2::/64'], $one->segments());
    }

    public function test_request_info_uses_the_method_and_path_without_a_route(): void
    {
        $limit = $this->limiter()->create(Request::create('/orders/5', 'POST'))->withRequestInfo();

        $this->assertSame(['route', 'POST /orders/5'], $limit->segments());
    }

    public function test_with_response_receives_headers_when_the_limit_passes(): void
    {
        $response = new Response('OK');

        $this->limiter()->withName('headers')->withTimeInterval(60)->withResponse($response)->limit(10);

        $this->assertSame('10', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('9', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertEqualsWithDelta(time() + 60, (int) $response->headers->get('X-RateLimit-Reset'), 2);
        $this->assertFalse($response->headers->has('Retry-After'));
    }

    public function test_refusals_and_blocks_dispatch_events(): void
    {
        Event::fake([RateLimitExceeded::class, IpBlocked::class]);

        $limit = $this->limiter()->withName('events')->withTimeInterval(60);
        $limit->attempt(1);
        $limit->attempt(1);
        $this->limiter()->blockIpAddress('198.51.100.7', 120);

        Event::assertDispatchedTimes(RateLimitExceeded::class, 1);
        Event::assertDispatched(RateLimitExceeded::class, fn (RateLimitExceeded $e) => $e->segments === ['name', 'events'] && ! $e->result->allowed);
        Event::assertDispatched(IpBlocked::class, fn (IpBlocked $e) => $e->ipAddress === '198.51.100.7' && $e->seconds === 120);
    }

    public function test_blocking_and_unblocking(): void
    {
        $limiter = $this->limiter();

        $this->assertFalse($limiter->isIpAddressBlocked('198.51.100.8'));
        $limiter->blockIpAddress('198.51.100.8', 120);
        $this->assertTrue($limiter->isIpAddressBlocked('198.51.100.8'));
        $this->assertGreaterThan(110, $limiter->blockedFor('198.51.100.8'));

        $limiter->blockIpAddress('2001:db8:9::1');
        $this->assertTrue($limiter->isIpAddressBlocked('2001:db8:9::ffff'), 'IPv6 blocks cover the /64.');
        $this->assertGreaterThan(86000, $limiter->blockedFor('2001:db8:9::1'));

        $limiter->unblockIpAddress('198.51.100.8');
        $this->assertFalse($limiter->isIpAddressBlocked('198.51.100.8'));
        $this->assertFalse($limiter->isIpAddressBlocked(null));
    }

    public function test_block_duration_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->limiter()->blockIpAddress('198.51.100.9', 0);
    }

    public function test_a_counter_that_lost_its_ttl_gets_one_back(): void
    {
        $limit = $this->limiter()->withName('orphan')->withTimeInterval(60);
        $limit->record();

        $redis = $this->rawRedis();
        $key = 'lrltest_'.$limit->key();
        $redis->persist($key);
        $this->assertSame(-1, $redis->ttl($key));

        $limit->record();
        $this->assertGreaterThan(0, $limit->ttl());
    }

    public function test_the_script_is_reloaded_after_a_script_flush(): void
    {
        $limit = $this->limiter()->withName('noscript')->withTimeInterval(60);
        $limit->record();

        $this->rawRedis()->script('flush');

        $this->assertSame(2, $limit->record());
        $this->assertSame(3, $limit->record());
    }

    public function test_whitelist_matches_addresses_and_ranges(): void
    {
        $limiter = $this->limiter();

        $this->assertTrue($limiter->isWhitelisted('127.0.0.1'));
        $this->assertTrue($limiter->isWhitelisted('::1'));
        $this->assertFalse($limiter->isWhitelisted('10.1.2.3'), 'Private ranges are not whitelisted by default.');
        $this->assertFalse($limiter->isWhitelisted(null));
        $this->assertFalse($limiter->isWhitelisted(''));

        config(['rate-limiter.whitelisted_ips' => ['192.0.2.0/24', '2001:db8::/32']]);
        $this->assertTrue($limiter->isWhitelisted('192.0.2.77'));
        $this->assertTrue($limiter->isWhitelisted('2001:db8:5::1'));
        $this->assertFalse($limiter->isWhitelisted('127.0.0.1'));
    }

    public function test_pending_limit_is_a_value_object(): void
    {
        $this->assertTrue((new \ReflectionClass(PendingLimit::class))->isReadOnly());
    }
}
