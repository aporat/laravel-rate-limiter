<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use Aporat\RateLimiter\LimitResult;
use Aporat\RateLimiter\RateLimiter;
use Aporat\RateLimiter\WindowState;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

class KeysAndResultsTest extends TestCase
{
    /**
     * Redis Cluster key slot (CRC16/XMODEM of the hash tag, mod 16384).
     */
    private static function slot(string $key): int
    {
        $start = strpos($key, '{');

        if ($start !== false) {
            $end = strpos($key, '}', $start + 1);

            if ($end !== false && $end !== $start + 1) {
                $key = substr($key, $start + 1, $end - $start - 1);
            }
        }

        $crc = 0;

        for ($i = 0, $length = strlen($key); $i < $length; $i++) {
            $crc ^= ord($key[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return $crc % 16384;
    }

    public function test_the_slot_function_matches_redis(): void
    {
        // Reference values from CLUSTER KEYSLOT.
        $this->assertSame(12182, self::slot('foo'));
        $this->assertSame(5061, self::slot('bar'));
        $this->assertSame(self::slot('user1000'), self::slot('{user1000}.following'));
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function subjects(): array
    {
        return [
            'ip' => [['ip', '203.0.113.7']],
            'ipv6' => [['ip', '2001:db8::/64']],
            'user and route' => [['user', '42', 'route', 'GET users/{user}']],
            'braces and colons' => [['name', '}{:}', 'user', '{evil}']],
        ];
    }

    /**
     * @param  list<string>  $segments
     */
    #[DataProvider('subjects')]
    public function test_all_windows_of_one_subject_share_a_cluster_slot(array $segments): void
    {
        $limiter = $this->limiter();
        $keys = array_map(fn (int $seconds) => $limiter->windowKey($segments, $seconds), [1, 60, 3600, 86400]);
        $slots = array_unique(array_map(self::slot(...), $keys));

        $this->assertCount(1, $slots, implode(', ', $keys));

        foreach ($keys as $key) {
            $this->assertSame(1, substr_count($key, '{'), $key);
            $this->assertSame(1, substr_count($key, '}'), $key);
        }
    }

    public function test_key_layout(): void
    {
        $this->assertSame('rl:{user:42:route:GET%20users%2F%7Buser%7D}:60s', $this->limiter()->windowKey(['user', '42', 'route', 'GET users/{user}'], 60));
        $this->assertSame('rl:blocked:{2001%3Adb8%3A%3A%2F64}', $this->limiter()->blockKey('2001:db8::/64'));
    }

    public function test_flush_all_refuses_a_cluster_connection(): void
    {
        $connection = (new ReflectionClass(PredisClusterConnection::class))->newInstanceWithoutConstructor();

        $factory = new class($connection) implements Factory
        {
            public function __construct(private readonly Connection $connection) {}

            public function connection($name = null): Connection
            {
                return $this->connection;
            }
        };

        $this->expectException(LogicException::class);
        (new RateLimiter(null, $factory))->flushAll();
    }

    public function test_most_restrictive_window_when_allowed(): void
    {
        $result = new LimitResult(true, [
            new WindowState(3600, 1000, 10, 3_000_000),
            new WindowState(60, 60, 58, 30_000),
            new WindowState(1, 10, 1, 500),
        ]);

        $this->assertSame(60, $result->mostRestrictive()?->seconds);
        $this->assertSame(0, $result->retryAfter());
        $this->assertSame(['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '2', 'X-RateLimit-Reset' => '1030'], $result->headers(1000));
    }

    public function test_ties_go_to_the_window_that_resets_last(): void
    {
        $result = new LimitResult(true, [
            new WindowState(60, 10, 8, 30_000),
            new WindowState(3600, 100, 98, 1_800_000),
        ]);

        $this->assertSame(3600, $result->mostRestrictive()?->seconds);
    }

    public function test_a_refusal_reports_the_exceeded_window_that_resets_last(): void
    {
        $result = new LimitResult(false, [
            new WindowState(3600, 5, 5, 1_200_400, true),
            new WindowState(60, 60, 60, 20_000, true),
            new WindowState(1, 10, 2, 900, false),
        ]);

        $this->assertSame(3600, $result->mostRestrictive()?->seconds);
        $this->assertSame(1201, $result->retryAfter());
        $this->assertSame([
            'X-RateLimit-Limit' => '5',
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => '2201',
            'Retry-After' => '1201',
        ], $result->headers(1000));
    }

    public function test_reset_is_never_zero_seconds(): void
    {
        $this->assertSame(1, (new WindowState(1, 1, 1, 3))->resetsIn());
    }

    public function test_degraded_results_have_no_headers(): void
    {
        $this->assertSame([], LimitResult::degraded()->headers());
        $this->assertTrue(LimitResult::degraded()->allowed);
    }
}
