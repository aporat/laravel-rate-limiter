<?php

namespace Aporat\RateLimiter\Laravel;

use Aporat\RateLimiter\RateLimiter;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class RateLimiterServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the service provider.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->setupConfig();
    }

    /**
     * Setup the config.
     *
     * @return void
     */
    private function setupConfig(): void
    {
        $source = realpath($raw = __DIR__.'/../config/rate-limiter.php') ?: $raw;

        if ($this->app->runningInConsole()) {
            $this->publishes([$source => config_path('rate-limiter.php')]);
        }

        $this->mergeConfigFrom($source, 'rate-limiter');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $this->registerRateLimitService();
    }

    /**
     * Register currency provider.
     *
     * @return void
     */
    public function registerRateLimitService()
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
