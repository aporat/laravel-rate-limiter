<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\Events\RateLimitExceeded;
use Aporat\RateLimiter\Middleware\RateLimit;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real requests through the HTTP kernel: routing, the `rate.limiter` alias,
 * exception rendering and trusted proxies.
 */
class MiddlewareRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('rate-limiter.limits', ['hourly' => 1000, 'minute' => 100, 'second' => 50]);
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('rate.limiter')->group(function () {
            Route::get('/default', fn () => 'default');
            Route::get('/other', fn () => 'other');
        });

        Route::middleware('rate.limiter:3,1')->get('/positional', fn () => 'positional');
        Route::middleware('rate.limiter:hourly=5,second=2')->get('/windows', fn () => 'windows');
        Route::middleware('rate.limiter:hourly=3,minute=100')->get('/tight-hourly', fn () => 'tight');
        Route::middleware(RateLimit::with(minute: 2, key: 'user'))->get('/per-user', fn () => 'user');
        Route::middleware('rate.limiter:minute=2,key=ip+route')->group(function () {
            Route::get('/route-a', fn () => 'a');
            Route::get('/route-b/{id}', fn () => 'b')->name('route-b');
        });
        Route::middleware('rate.limiter:minute=2,name=api')->get('/named', fn () => 'named');
        Route::middleware('rate.limiter:minute=2')->get('/unnamed', fn () => 'unnamed');
        Route::middleware('rate.limiter:minute=lots')->get('/bad-value', fn () => 'bad');
        Route::middleware('rate.limiter:weekly=5')->get('/bad-window', fn () => 'bad');
        Route::middleware('rate.limiter:key=device')->get('/bad-key', fn () => 'bad');
        Route::middleware('rate.limiter:minute=0,second=0')->get('/disabled', fn () => 'disabled');
    }

    /**
     * @param  array<string, string>  $server
     * @return TestResponse<Response>
     */
    private function hit(string $uri, string $ip = '203.0.113.20', array $server = []): TestResponse
    {
        return $this->call('GET', $uri, [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json'] + $server);
    }

    public function test_a_refused_request_renders_as_429_with_headers(): void
    {
        $this->hit('/positional')->assertOk();
        $this->hit('/positional')->assertOk();
        $this->hit('/positional')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');

        $response = $this->hit('/positional');

        $response->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', '3')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After');
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(59, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
        $this->assertEqualsWithDelta(time() + $retryAfter, (int) $response->headers->get('X-RateLimit-Reset'), 1);
        $this->assertSame('Too Many Requests', $response->json('message'));
    }

    public function test_successful_responses_report_the_most_restrictive_window(): void
    {
        // hourly=3 is far tighter than minute=100: the headers must say so.
        $remaining = [];

        for ($i = 1; $i <= 3; $i++) {
            $response = $this->hit('/tight-hourly')->assertOk()->assertHeader('X-RateLimit-Limit', '3');
            $remaining[] = $response->headers->get('X-RateLimit-Remaining');
        }

        $this->assertSame(['2', '1', '0'], $remaining);
        $this->hit('/tight-hourly')->assertStatus(429)->assertHeader('X-RateLimit-Limit', '3');
    }

    public function test_refused_requests_do_not_use_up_the_longer_windows(): void
    {
        // Regression for H1: hourly=5, second=2. A burst used to burn the hourly budget.
        $statuses = array_map(fn () => $this->hit('/windows')->status(), range(1, 6));
        $this->assertSame([200, 200, 429, 429, 429, 429], $statuses);

        $this->assertSame(2, $this->limiter()->countFor(['ip', '203.0.113.20'], 3600));

        usleep(1_100_000);

        $this->hit('/windows')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
        $this->assertSame(3, $this->limiter()->countFor(['ip', '203.0.113.20'], 3600));
    }

    public function test_every_window_is_checked_before_any_is_counted(): void
    {
        // The hourly window refuses first: neither window may be incremented.
        for ($i = 1; $i <= 3; $i++) {
            $this->hit('/tight-hourly')->assertOk();
        }

        $this->hit('/tight-hourly')->assertStatus(429);
        $this->assertSame(3, $this->limiter()->countFor(['ip', '203.0.113.20'], 60));
    }

    public function test_the_default_whitelist_is_loopback_only(): void
    {
        $this->appConfig()->set('rate-limiter.limits', ['second' => 1]);

        $this->hit('/default', '127.0.0.1')->assertOk();
        $this->hit('/default', '127.0.0.1')->assertOk()->assertHeaderMissing('X-RateLimit-Limit');

        // A private address (e.g. a load balancer) is limited like anyone else.
        $this->hit('/default', '10.1.2.3')->assertOk();
        $this->hit('/default', '10.1.2.3')->assertStatus(429);
    }

    public function test_without_trusted_proxies_x_forwarded_for_is_ignored(): void
    {
        $this->appConfig()->set('rate-limiter.limits', ['second' => 1]);

        $this->hit('/default', '203.0.113.30', ['HTTP_X_FORWARDED_FOR' => '198.51.100.1'])->assertOk();
        $this->hit('/default', '203.0.113.30', ['HTTP_X_FORWARDED_FOR' => '198.51.100.2'])->assertStatus(429);
        $this->hit('/default', '203.0.113.30', ['HTTP_X_FORWARDED_FOR' => '127.0.0.1'])->assertStatus(429);
    }

    public function test_with_trusted_proxies_the_forwarded_client_is_limited(): void
    {
        TrustProxies::at('203.0.113.40');
        $this->appConfig()->set('rate-limiter.limits', ['second' => 1]);

        $this->hit('/default', '203.0.113.40', ['HTTP_X_FORWARDED_FOR' => '198.51.100.11'])->assertOk();
        $this->hit('/default', '203.0.113.40', ['HTTP_X_FORWARDED_FOR' => '198.51.100.12'])->assertOk();
        $this->hit('/default', '203.0.113.40', ['HTTP_X_FORWARDED_FOR' => '198.51.100.11'])->assertStatus(429);

        // An untrusted peer cannot pick its address.
        $this->hit('/default', '203.0.113.41', ['HTTP_X_FORWARDED_FOR' => '198.51.100.13'])->assertOk();
        $this->hit('/default', '203.0.113.41', ['HTTP_X_FORWARDED_FOR' => '198.51.100.14'])->assertStatus(429);
    }

    public function test_routes_without_a_name_share_the_per_ip_budget(): void
    {
        $this->appConfig()->set('rate-limiter.limits', ['minute' => 2]);

        $this->hit('/default')->assertOk();
        $this->hit('/other')->assertOk();
        $this->hit('/default')->assertStatus(429);
    }

    public function test_name_gives_a_route_group_its_own_counters(): void
    {
        $this->hit('/unnamed')->assertOk();
        $this->hit('/unnamed')->assertOk();
        $this->hit('/unnamed')->assertStatus(429);

        $this->hit('/named')->assertOk();
        $this->hit('/named')->assertOk();
        $this->hit('/named')->assertStatus(429);
    }

    public function test_key_user_counts_per_authenticated_user_and_falls_back_to_ip(): void
    {
        $alice = new GenericUser(['id' => 1]);
        $bob = new GenericUser(['id' => 2]);

        $this->actingAs($alice);
        $this->hit('/per-user')->assertOk();
        $this->hit('/per-user')->assertOk();
        $this->hit('/per-user')->assertStatus(429);

        $this->actingAs($bob);
        $this->hit('/per-user')->assertOk();

        $this->assertSame(2, $this->limiter()->countFor(['user', '1'], 60));
        $this->assertSame(1, $this->limiter()->countFor(['user', '2'], 60));
    }

    public function test_key_user_falls_back_to_the_ip_for_guests(): void
    {
        $this->hit('/per-user', '203.0.113.50')->assertOk();
        $this->hit('/per-user', '203.0.113.50')->assertOk();
        $this->hit('/per-user', '203.0.113.50')->assertStatus(429);
        $this->hit('/per-user', '203.0.113.51')->assertOk();
    }

    public function test_key_route_counts_per_route_pattern(): void
    {
        $this->hit('/route-a')->assertOk();
        $this->hit('/route-a')->assertOk();
        $this->hit('/route-a')->assertStatus(429);

        // Different ids hit one route pattern (named route-b), so they share a counter.
        $this->hit('/route-b/1')->assertOk();
        $this->hit('/route-b/2')->assertOk();
        $this->hit('/route-b/3')->assertStatus(429);

        $this->assertSame(2, $this->limiter()->countFor(['ip', '203.0.113.20', 'route', 'GET route-b'], 60));
        $this->assertSame(2, $this->limiter()->countFor(['ip', '203.0.113.20', 'route', 'GET route-a'], 60));
    }

    public function test_windows_set_to_zero_disable_limiting(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->hit('/disabled')->assertOk()->assertHeaderMissing('X-RateLimit-Limit');
        }
    }

    public function test_invalid_parameters_fail_loudly(): void
    {
        $this->withoutExceptionHandling();

        foreach (['/bad-value' => 'non-negative integer', '/bad-window' => 'unknown option [weekly]', '/bad-key' => 'unknown key type [device]'] as $uri => $message) {
            try {
                $this->hit($uri);
                $this->fail("Expected {$uri} to throw.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_the_with_helper_builds_a_middleware_string(): void
    {
        $this->assertSame('rate.limiter', RateLimit::with());
        $this->assertSame(
            'rate.limiter:minute=60,hourly=1000,key=user+route,name=api',
            RateLimit::with(minute: 60, hourly: 1000, key: ['user', 'route'], name: 'api')
        );
    }

    public function test_a_blocked_ip_gets_429_with_the_block_ttl(): void
    {
        $this->limiter()->blockIpAddress('203.0.113.60', 600);

        $response = $this->hit('/default', '203.0.113.60')->assertStatus(429);

        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(599, $retryAfter);
        $this->assertLessThanOrEqual(600, $retryAfter);
        $this->assertEqualsWithDelta(time() + 600, (int) $response->headers->get('X-RateLimit-Reset'), 2);
        $this->assertSame(0, $this->limiter()->countFor(['ip', '203.0.113.60'], 60), 'Blocked requests are not counted.');
    }

    public function test_requests_without_a_client_ip_are_not_lumped_together(): void
    {
        $this->appConfig()->set('rate-limiter.limits', ['second' => 1]);

        for ($i = 1; $i <= 3; $i++) {
            $this->hit('/default', '')->assertOk()->assertHeaderMissing('X-RateLimit-Limit');
        }
    }

    public function test_headers_can_be_turned_off_for_successful_responses(): void
    {
        $this->appConfig()->set('rate-limiter.headers', false);

        $this->hit('/positional')->assertOk()->assertHeaderMissing('X-RateLimit-Limit');
        $this->hit('/positional');
        $this->hit('/positional');
        $this->hit('/positional')->assertStatus(429)->assertHeader('X-RateLimit-Limit', '3')->assertHeader('Retry-After');
    }

    public function test_refusals_dispatch_an_event(): void
    {
        Event::fake([RateLimitExceeded::class]);

        for ($i = 1; $i <= 4; $i++) {
            $this->hit('/positional');
        }

        Event::assertDispatchedTimes(RateLimitExceeded::class, 1);
        Event::assertDispatched(RateLimitExceeded::class, fn (RateLimitExceeded $e) => $e->request?->getPathInfo() === '/positional');
    }

    public function test_nothing_is_logged_by_default(): void
    {
        $lines = [];
        Log::listen(function ($event) use (&$lines) {
            $lines[] = $event->message;
        });

        for ($i = 1; $i <= 4; $i++) {
            $this->hit('/positional');
        }

        $this->assertSame([], $lines);
    }

    public function test_logging_writes_one_warning_with_the_path_only(): void
    {
        $this->appConfig()->set('rate-limiter.log_errors', true);
        $lines = [];
        Log::listen(function ($event) use (&$lines) {
            $lines[] = [$event->level, $event->message, $event->context];
        });

        for ($i = 1; $i <= 4; $i++) {
            $this->call('GET', '/positional?token=SECRET1', ['password' => 'SECRET2'], [], [], [
                'REMOTE_ADDR' => '203.0.113.70',
                'HTTP_AUTHORIZATION' => 'Bearer SECRET3',
            ]);
        }

        $this->assertCount(1, $lines);
        [$level, $message, $context] = $lines[0];
        $this->assertSame('warning', $level);
        $this->assertSame('Rate limit exceeded', $message);
        $this->assertSame('/positional', $context['path']);
        $this->assertSame('203.0.113.70', $context['ip']);
        $this->assertStringNotContainsString('SECRET', json_encode($lines, JSON_THROW_ON_ERROR));
    }
}
