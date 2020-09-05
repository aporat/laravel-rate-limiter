<?php

namespace Aporat\RateLimiter;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class RateLimiterServiceProvider extends ServiceProvider implements DeferrableProvider
{

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerResources();
        $this->registerRateLimitService();
    }

    /**
     * Register the rate limiter service.
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
     * Register resources.
     *
     * @return void
     */
    public function registerResources()
    {
        if ($this->isLumen()) {
            $this->app->configure('rate-limiter');
        } elseif ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__ . '/Config/rate-limiter.php' => config_path('rate-limiter.php')],
                'rate-limiter'
            );
        }

        $this->mergeConfigFrom(
            __DIR__ . '/Config/rate-limiter.php',
            'rate-limiter'
        );
    }

    /**
     * Get the services provided by the provider.
     *
     * @return string
     */
    public function provides()
    {
        return 'rate-limiter';
    }

    /**
     * Check if package is running under Lumen app
     *
     * @return bool
     */
    protected function isLumen()
    {
        return Str::contains($this->app->version(), 'Lumen') === true;
    }
}
