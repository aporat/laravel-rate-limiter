<?php

declare(strict_types=1);

namespace Aporat\RateLimiter;

use Aporat\RateLimiter\Middleware\RateLimit;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class RateLimiterServiceProvider extends ServiceProvider
{
    private const string CONFIG_PATH = __DIR__.'/../config/rate-limiter.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'rate-limiter');

        // Holds no request state and resolves config/Redis per call, so a
        // singleton is safe under Octane.
        $this->app->singleton(RateLimiter::class, static fn () => new RateLimiter);
        $this->app->alias(RateLimiter::class, 'rate-limiter');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_PATH => $this->app->configPath('rate-limiter.php')], 'config');
        }

        $router = $this->app->make('router');

        if ($router instanceof Router) {
            $router->aliasMiddleware('rate.limiter', RateLimit::class);
        }
    }
}
