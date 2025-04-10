<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Exceptions\RateLimitException;
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
}
