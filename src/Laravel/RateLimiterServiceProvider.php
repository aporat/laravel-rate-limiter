<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Laravel;

use Aporat\RateLimiter\RateLimiter;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Laravel Rate Limiter package.
 *
 * Registers the RateLimiter service as a singleton and manages configuration
 * merging and publishing for rate limiting functionality.
 */
class RateLimiterServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Path to the package's configuration file.
     *
     * @var string
     */
    private const CONFIG_PATH = __DIR__.'/../../config/rate-limiter.php';

    /**
     * Register services in the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'rate-limiter');
        $this->registerRateLimiterService();
    }

    /**
     * Bootstrap services and publish configuration.
     */
    public function boot(): void
    {
        $this->publishes([self::CONFIG_PATH => config_path('rate-limiter.php')], 'config');
    }

    /**
     * Register the RateLimiter singleton in the container.
     */
    protected function registerRateLimiterService(): void
    {
        $this->app->singleton('rate-limiter', fn ($app) => new RateLimiter($app['config']['rate-limiter']));
    }

    /**
     * Get the services provided by this provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['rate-limiter'];
    }
}
