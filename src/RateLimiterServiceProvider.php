<?php

declare(strict_types=1);

namespace Aporat\RateLimiter;

use Aporat\RateLimiter\Middleware\RateLimit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Laravel Rate Limiter package.
 *
 * Registers the RateLimiter service as a singleton and manages configuration
 * merging and publishing for rate limiting functionality.
 *
 * This provider is intentionally not deferred: a deferred provider only boots when
 * one of its `provides()` bindings is resolved, which would mean the middleware
 * alias and the publishable config were registered too late (or never).
 */
class RateLimiterServiceProvider extends ServiceProvider
{
    /**
     * Path to the package's configuration file.
     */
    private const string CONFIG_PATH = __DIR__.'/../config/rate-limiter.php';

    /**
     * Register services in the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'rate-limiter');

        $this->app->singleton(RateLimiter::class, fn (Application $app) => new RateLimiter(
            (array) $app['config']->get('rate-limiter', [])
        ));

        $this->app->alias(RateLimiter::class, 'rate-limiter');
    }

    /**
     * Bootstrap services and publish configuration.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_PATH => config_path('rate-limiter.php')], 'config');
        }

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('rate.limiter', RateLimit::class);
    }

    /**
     * Get the services provided by this provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [RateLimiter::class, 'rate-limiter'];
    }
}
