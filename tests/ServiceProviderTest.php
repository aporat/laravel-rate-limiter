<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Facades\RateLimiter as RateLimiterFacade;
use Aporat\RateLimiter\Middleware\RateLimit;
use Aporat\RateLimiter\RateLimiter;
use Aporat\RateLimiter\RateLimiterServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Testing\PendingCommand;
use ReflectionClass;
use ReflectionProperty;

class ServiceProviderTest extends TestCase
{
    public function test_it_registers_a_singleton_and_aliases(): void
    {
        $this->assertSame($this->limiter(), $this->app?->make('rate-limiter'));
        $this->assertSame($this->limiter(), RateLimiterFacade::getFacadeRoot());

        $router = app('router');
        $this->assertInstanceOf(Router::class, $router);
        $this->assertSame(RateLimit::class, $router->getMiddleware()['rate.limiter']);
    }

    public function test_default_config_is_merged(): void
    {
        $config = $this->appConfig();

        $this->assertSame(['127.0.0.1/32', '::1/128'], $config->get('rate-limiter.whitelisted_ips'));
        $this->assertFalse($config->get('rate-limiter.log_errors'));
        $this->assertTrue($config->get('rate-limiter.headers'));
        $this->assertSame('open', $config->get('rate-limiter.on_redis_failure'));
        $this->assertSame('ip', $config->get('rate-limiter.key'));
    }

    public function test_the_singleton_holds_no_per_request_state(): void
    {
        // Octane: the long-lived instance must not capture config, Redis or
        // request objects; it resolves them from the current container per call.
        $instanceProperties = array_filter(
            (new ReflectionClass(RateLimiter::class))->getProperties(),
            static fn (ReflectionProperty $p) => ! $p->isStatic()
        );

        foreach ($instanceProperties as $property) {
            $this->assertNull($property->getValue($this->limiter()), $property->getName());
        }

        $this->appConfig()->set('rate-limiter.prefix', 'changed');
        $this->assertStringStartsWith('changed:', $this->limiter()->windowKey(['name', 'x'], 60));
        $this->appConfig()->set('rate-limiter.prefix', 'rl');
    }

    public function test_config_can_be_published(): void
    {
        $command = $this->artisan('vendor:publish', ['--provider' => RateLimiterServiceProvider::class, '--tag' => 'config', '--force' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();

        $published = $this->app?->configPath('rate-limiter.php');
        $this->assertIsString($published);
        $this->assertFileExists($published);
        @unlink($published);
    }
}
