<?php

namespace Aporat\RateLimiter\Laravel;

use Aporat\RateLimiter\RateLimiter;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class RateLimiterServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $configPath = __DIR__.'/../../config/rate-limiter.php';
        $this->mergeConfigFrom($configPath, 'rate-limiter');
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot(): void
    {
        $configPath = __DIR__.'/../../config/rate-limiter.php';
        $this->publishes([$configPath => $this->getConfigPath()], 'config');

        $this->registerRateLimitService();
    }

    /**
     * Get the config path.
     *
     * @return string
     */
    protected function getConfigPath(): string
    {
        return config_path('rate-limiter.php');
    }

    /**
     * Register the rate limit provider.
     *
     * @return void
     */
    public function registerRateLimitService(): void
    {
        $this->app->singleton('rate-limiter', function ($app) {
            $config = $app->make('config')->get('rate-limiter');

            return new RateLimiter($config);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return string[]
     */
    public function provides(): array
    {
        return [
            'rate-limiter',
        ];
    }
}
