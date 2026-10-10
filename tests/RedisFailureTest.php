<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimiterUnavailableException;
use Aporat\RateLimiter\RateLimiter;
use Aporat\RateLimiter\Tests\Fixtures\CountingRedisFactory;
use Aporat\RateLimiter\Tests\Fixtures\RecordingExceptionHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Predis\PredisException;
use RedisException;
use Symfony\Component\HttpFoundation\Response;

class RedisFailureTest extends TestCase
{
    private CountingRedisFactory $factory;

    private RecordingExceptionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $inner = $this->app?->make(ExceptionHandler::class);
        $this->assertInstanceOf(ExceptionHandler::class, $inner);
        $this->handler = new RecordingExceptionHandler($inner);
        $this->app?->instance(ExceptionHandler::class, $this->handler);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        // Nothing listens on port 1: connections are refused immediately.
        $config->set('database.redis.down', ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5]);
        $config->set('rate-limiter.limits', ['minute' => 1]);
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('rate.limiter')->get('/limited', fn () => 'ok');
    }

    /**
     * Point the limiter at the dead connection and count connection attempts.
     */
    private function breakRedis(string $mode, int $backoff = 30): void
    {
        $config = $this->appConfig();
        $config->set('rate-limiter.connection', 'down');
        $config->set('rate-limiter.on_redis_failure', $mode);
        $config->set('rate-limiter.failure_backoff_seconds', $backoff);

        $inner = $this->app?->make('redis');
        $this->assertInstanceOf(Factory::class, $inner);
        $this->factory = new CountingRedisFactory($inner);
        $this->app?->instance('redis', $this->factory);
    }

    private function restoreRedis(): void
    {
        $this->appConfig()->set('rate-limiter.connection', 'default');
        RateLimiter::flushState();
    }

    protected function tearDown(): void
    {
        $this->restoreRedis();

        parent::tearDown();
    }

    /**
     * Redis errors passed to report() (the 503 exception itself reports nothing).
     *
     * @return list<\Throwable>
     */
    private function redisReports(): array
    {
        return array_values(array_filter(
            $this->handler->reported,
            static fn (\Throwable $e) => $e instanceof RedisException || $e instanceof PredisException
        ));
    }

    /**
     * @return TestResponse<Response>
     */
    private function hit(): TestResponse
    {
        return $this->call('GET', '/limited', [], [], [], ['REMOTE_ADDR' => '203.0.113.80', 'HTTP_ACCEPT' => 'application/json']);
    }

    public function test_fail_open_lets_requests_through_and_reports_once_per_backoff(): void
    {
        $this->breakRedis('open');

        for ($i = 1; $i <= 5; $i++) {
            $this->hit()->assertOk()->assertHeaderMissing('X-RateLimit-Limit');
        }

        $this->assertCount(1, $this->redisReports());
        $this->assertCount(1, $this->handler->reported, 'Nothing else is reported.');
        $this->assertSame(1, $this->factory->calls, 'Redis is not contacted again during the backoff.');
    }

    public function test_fail_closed_answers_503_with_retry_after(): void
    {
        $this->breakRedis('closed', 30);

        $first = $this->hit()->assertStatus(503);
        $this->hit()->assertStatus(503);

        $retryAfter = (int) $first->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(29, $retryAfter);
        $this->assertLessThanOrEqual(30, $retryAfter);
        $this->assertCount(1, $this->redisReports());
        $this->assertFalse((new RateLimiterUnavailableException)->report() === false, 'The 503 exception reports nothing itself.');
        $this->assertSame(1, $this->factory->calls);
    }

    public function test_redis_is_retried_once_the_backoff_ends(): void
    {
        $this->breakRedis('open', 0);

        $this->hit()->assertOk();
        $this->hit()->assertOk();

        // Without a backoff every Redis call is retried: block check + limit, per request.
        $this->assertSame(4, $this->factory->calls);
        $this->assertCount(4, $this->redisReports());

        // Redis comes back: limiting resumes on the next request.
        $this->restoreRedis();
        $this->hit()->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
        $this->hit()->assertStatus(429);
    }

    public function test_builder_calls_fail_open_too(): void
    {
        $this->breakRedis('open');

        $limit = $this->limiter()->withName('down')->withTimeInterval(60);

        $result = $limit->limit(1);
        $this->assertTrue($result->allowed);
        $this->assertTrue($result->degraded);
        $this->assertSame([], $result->headers());
        $this->assertSame(0, $limit->record());
        $this->assertSame(0, $limit->count());
        $this->assertSame(0, $limit->ttl());
        $this->assertFalse($this->limiter()->isIpAddressBlocked('203.0.113.80'));
    }

    public function test_admin_operations_always_throw(): void
    {
        $this->breakRedis('open');

        try {
            $this->limiter()->blockIpAddress('203.0.113.81');
            $this->fail('Expected the Redis error to be thrown.');
        } catch (RedisException|PredisException $e) {
            $this->assertStringContainsString('refused', strtolower($e->getMessage()));
        }

        $this->assertCount(0, $this->redisReports());
    }

    public function test_programming_errors_are_not_swallowed(): void
    {
        $this->breakRedis('open');
        $this->appConfig()->set('rate-limiter.connection', 'missing-connection');

        $this->expectException(\InvalidArgumentException::class);
        $this->limiter()->withName('x')->limit(1);
    }
}
