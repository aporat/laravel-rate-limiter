<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;

class RateLimitExceptionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('rate-limiter.log_errors', true);
    }

    public function test_it_logs_error_when_enabled()
    {
        Log::shouldReceive('error')
            ->once()
            ->with(\Mockery::on(function ($message) {
                return str_contains($message, 'RateLimitException');
            }));

        $exception = new RateLimitException('Test exception');
        $exception->report();
    }

    public function test_it_does_not_log_when_disabled()
    {
        config(['rate-limiter.log_errors' => false]);

        Log::shouldReceive('error')->never();

        $exception = new RateLimitException('Test exception');
        $exception->report();
    }

    public function test_get_status_code_returns_429()
    {
        $exception = new RateLimitException;
        $this->assertEquals(429, $exception->getStatusCode());
    }

    public function test_report_logs_debug_info_and_stack_trace()
    {
        Config::set('rate-limiter.log_errors', true);
        Log::shouldReceive('error')->once()->withArgs(function ($message) {
            return str_contains($message, 'RateLimitException') &&
                str_contains($message, 'Too Many Requests') &&
                str_contains($message, 'tag') &&
                str_contains($message, 'trace');
        });

        $request = Request::create('/test', 'GET');
        $debug = ['tag' => 'debug:test'];

        $exception = new RateLimitException(
            'Too Many Requests',
            $request,
            $debug,
            true // stack trace enabled
        );

        $exception->report();
    }

    public function test_report_respects_log_errors_config_false()
    {
        Config::set('rate-limiter.log_errors', false);
        Log::shouldReceive('error')->never();

        $exception = new RateLimitException('Silent');
        $exception->report();
    }
}
