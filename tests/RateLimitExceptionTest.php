<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RateLimitExceptionTest extends TestCase
{
    public function test_it_is_an_http_exception_but_not_symfonys_http_exception(): void
    {
        $exception = new RateLimitException;

        // HttpExceptionInterface gives Laravel the 429; extending HttpException would
        // put it on the framework's internal dontReport list and silence the app.
        $this->assertInstanceOf(HttpExceptionInterface::class, $exception);
        $this->assertNotContains(HttpException::class, class_parents(RateLimitException::class) ?: []);
        $this->assertSame(429, $exception->getStatusCode());
        $this->assertSame('Too Many Requests', $exception->getMessage());
    }

    public function test_it_does_not_define_render_so_the_app_keeps_control(): void
    {
        $this->assertFalse(method_exists(RateLimitException::class, 'render'));
    }

    public function test_retry_after_is_exposed_as_a_header(): void
    {
        $this->assertSame([], (new RateLimitException)->getHeaders());
        $this->assertSame(
            ['Retry-After' => '30'],
            (new RateLimitException(retryAfter: 30))->getHeaders()
        );
    }

    public function test_it_logs_when_enabled_and_reports_itself_as_handled(): void
    {
        Config::set('rate-limiter.log_errors', true);

        Log::shouldReceive('error')
            ->once()
            ->with(Mockery::on(fn ($message) => str_contains($message, 'RateLimitException')));

        $this->assertTrue((new RateLimitException('Test exception'))->report());
    }

    public function test_it_returns_false_when_logging_is_disabled_so_app_reporters_still_run(): void
    {
        Config::set('rate-limiter.log_errors', false);

        Log::shouldReceive('error')->never();

        $this->assertFalse((new RateLimitException('Silent'))->report());
    }

    public function test_it_logs_debug_info_and_trace(): void
    {
        Config::set('rate-limiter.log_errors', true);

        Log::shouldReceive('error')->once()->withArgs(fn ($message) => str_contains($message, 'RateLimitException')
            && str_contains($message, 'Too Many Requests')
            && str_contains($message, 'tag')
            && str_contains($message, 'RateLimitExceptionTest'));

        (new RateLimitException(
            'Too Many Requests',
            Request::create('/test'),
            ['tag' => 'debug:test'],
            traceReporting: true,
        ))->report();
    }

    public function test_it_redacts_credentials_from_the_log(): void
    {
        Config::set('rate-limiter.log_errors', true);

        $request = Request::create('/login', 'POST', ['email' => 'a@b.c', 'password' => 'hunter2']);
        $request->headers->set('X-Auth-Signature', 'super-secret-signature');
        $request->headers->set('Authorization', 'Bearer abc123');

        Log::shouldReceive('error')->once()->withArgs(function ($message) {
            $this->assertStringNotContainsString('hunter2', $message);
            $this->assertStringNotContainsString('super-secret-signature', $message);
            $this->assertStringNotContainsString('abc123', $message);
            $this->assertStringContainsString('[redacted]', $message);
            $this->assertStringContainsString('a@b.c', $message);

            return true;
        });

        (new RateLimitException('Too Many Requests', $request))->report();
    }
}
