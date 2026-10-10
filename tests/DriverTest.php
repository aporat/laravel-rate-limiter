<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\RateLimiter;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The script, EVALSHA fallback, prefixes and flushAll() against both clients,
 * whatever REDIS_CLIENT the rest of the suite runs with.
 */
class DriverTest extends TestCase
{
    /**
     * @return array<string, array{string, class-string}>
     */
    public static function clients(): array
    {
        return [
            'phpredis' => ['phpredis', PhpRedisConnection::class],
            'predis' => ['predis', PredisConnection::class],
        ];
    }

    /**
     * @param  class-string  $connectionClass
     */
    #[DataProvider('clients')]
    public function test_multi_window_attempts_work_on_both_clients(string $client, string $connectionClass): void
    {
        $this->useRedisClient($client);
        $this->assertInstanceOf($connectionClass, $this->redisConnection());

        $limiter = $this->limiter();
        $segments = ['name', 'driver-'.$client];

        $first = $limiter->attempt($segments, [3600 => 3, 60 => 2]);
        $this->assertTrue($first->allowed);
        $this->assertSame([1, 1], array_map(fn ($w) => $w->count, $first->windows));

        $limiter->attempt($segments, [3600 => 3, 60 => 2]);
        $refused = $limiter->attempt($segments, [3600 => 3, 60 => 2]);

        $this->assertFalse($refused->allowed);
        $this->assertSame([2, 2], array_map(fn ($w) => $w->count, $refused->windows));
        $this->assertSame([false, true], array_map(fn ($w) => $w->exceeded, $refused->windows));
        $this->assertSame(60, $refused->mostRestrictive()?->seconds);
        $this->assertSame(2, $limiter->countFor($segments, 3600));
    }

    /**
     * @param  class-string  $connectionClass
     */
    #[DataProvider('clients')]
    public function test_evalsha_falls_back_to_eval_after_a_script_flush(string $client, string $connectionClass): void
    {
        $this->useRedisClient($client);
        $this->assertInstanceOf($connectionClass, $this->redisConnection());
        $limit = $this->limiter()->withName('sha-'.$client)->withTimeInterval(60);

        $this->rawRedis()->script('flush');
        $this->assertSame(1, $limit->record());

        $this->assertTrue((bool) $this->rawRedis()->script('exists', sha1(RateLimiter::SCRIPT))[0]);
        $this->assertSame(2, $limit->record());

        $this->rawRedis()->script('flush');
        $this->assertSame(3, $limit->record());
    }

    /**
     * @param  class-string  $connectionClass
     */
    #[DataProvider('clients')]
    public function test_keys_carry_the_connection_prefix_and_flush_all_removes_them(string $client, string $connectionClass): void
    {
        $this->useRedisClient($client);
        $this->assertInstanceOf($connectionClass, $this->redisConnection());
        $limit = $this->limiter()->withName('flush-'.$client)->withTimeInterval(60);
        $limit->record();
        $this->limiter()->blockIpAddress('198.51.100.50', 60);

        $redis = $this->rawRedis();
        $this->assertSame(1, (int) $redis->exists('lrltest_'.$limit->key()));
        $redis->set('lrltest_unrelated', '1');

        $this->limiter()->flushAll();

        $this->assertSame(0, (int) $redis->exists('lrltest_'.$limit->key()));
        $this->assertFalse($this->limiter()->isIpAddressBlocked('198.51.100.50'));
        $this->assertSame(1, (int) $redis->exists('lrltest_unrelated'), 'Keys outside the namespace survive.');
        $redis->del('lrltest_unrelated');
    }
}
