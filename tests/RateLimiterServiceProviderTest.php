<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Middleware\RateLimit;
use Aporat\RateLimiter\RateLimiter;
use Aporat\RateLimiter\RateLimiterServiceProvider;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Routing\Router;

class RateLimiterServiceProviderTest extends TestCase
{
    public function test_service_is_registered_as_a_singleton_under_both_keys(): void
    {
        $instance = $this->app->make(RateLimiter::class);

        $this->assertInstanceOf(RateLimiter::class, $instance);
        $this->assertSame($instance, $this->app->make(RateLimiter::class));
        $this->assertSame($instance, $this->app->make('rate-limiter'));
    }

    public function test_provider_is_not_deferred_so_boot_side_effects_always_run(): void
    {
        $this->assertNotContains(
            DeferrableProvider::class,
            class_implements(RateLimiterServiceProvider::class) ?: []
        );
    }

    public function test_middleware_alias_is_registered(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];

        $this->assertSame(RateLimit::class, $router->getMiddleware()['rate.limiter'] ?? null);
    }

    public function test_middleware_is_resolvable_from_the_container(): void
    {
        $this->assertInstanceOf(RateLimit::class, $this->app->make(RateLimit::class));
    }

    public function test_config_is_merged(): void
    {
        $config = $this->app['config']->get('rate-limiter');

        $this->assertIsArray($config);
        $this->assertSame(3000, $config['limits']['hourly']);
        $this->assertContains('10.0.0.0/8', $config['whitelisted_ips']);
    }

    public function test_config_is_publishable(): void
    {
        $target = $this->app->configPath('rate-limiter.php');

        $this->artisan('vendor:publish', [
            '--provider' => RateLimiterServiceProvider::class,
            '--tag' => 'config',
            '--force' => true,
        ]);

        $this->assertFileExists($target);
        $this->assertFileEquals(realpath(__DIR__.'/../config/rate-limiter.php'), $target);

        unlink($target);
    }
}
