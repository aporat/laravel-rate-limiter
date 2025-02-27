<?php

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Laravel\RateLimiterServiceProvider;
use Aporat\RateLimiter\RateLimiter;
use Orchestra\Testbench\TestCase;

class RateLimiterServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [RateLimiterServiceProvider::class];
    }

    public function test_service_is_registered_as_singleton(): void
    {
        $this->assertTrue($this->app->bound('rate-limiter'));
        $instance1 = $this->app->make('rate-limiter');
        $instance2 = $this->app->make('rate-limiter');

        $this->assertInstanceOf(RateLimiter::class, $instance1);
        $this->assertSame($instance1, $instance2, 'RateLimiter should be a singleton');
    }

    public function test_config_is_merged(): void
    {
        $config = $this->app['config']->get('rate-limiter');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('limits', $config);
        $this->assertEquals(3000, $config['limits']['hourly']);
    }

    public function test_config_is_publishable(): void
    {
        $sourcePath = realpath(__DIR__.'/../config/rate-limiter.php');
        $targetPath = $this->app->configPath('rate-limiter.php');

        $this->artisan('vendor:publish', [
            '--provider' => RateLimiterServiceProvider::class,
            '--tag'      => 'config',
            '--force'    => true,
        ]);

        $this->assertFileExists($targetPath);
        $this->assertFileEquals($sourcePath, $targetPath);

        unlink($targetPath); // Cleanup
    }
}
