<?php

namespace Aporat\RateLimiter;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\ServiceProvider;
use Laravel\Lumen\Application as LumenApplication;

class RateLimiterServiceProvider extends ServiceProvider implements DeferrableProvider
{

    /**
     * Bootstrap the application service
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app instanceof LaravelApplication && $this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__.'/Config/rate_limiter.php' => config_path('rate_limiter.php')],
                'rate_limiter'
            );
        } elseif ($this->app instanceof LumenApplication) {
            $this->app->configure('rate_limiter');
        }

        $this->mergeConfigFrom(
            __DIR__ . '/Config/rate_limiter.php',
            'rate_limiter'
        );
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('rate-limiter', function ($app) {
            $config = $app->make('config')->get('rate_limiter');
            return new RateLimiter($config);
        });
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
}
