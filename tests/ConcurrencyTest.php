<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Many processes racing on one subject: the check-and-count must be atomic.
 */
class ConcurrencyTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function clients(): array
    {
        return ['phpredis' => ['phpredis'], 'predis' => ['predis']];
    }

    #[DataProvider('clients')]
    public function test_parallel_processes_never_exceed_the_limit(string $client): void
    {
        $processes = 16;
        $attemptsEach = 20;
        $worker = __DIR__.'/Fixtures/concurrency-worker.php';
        $handles = [];

        for ($i = 0; $i < $processes; $i++) {
            $command = [PHP_BINARY, $worker, $client, self::redisHost(), (string) self::redisPort(), (string) self::DATABASE, (string) $attemptsEach];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $handles[] = [$process, $pipes];
        }

        $allowed = 0;

        foreach ($handles as [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $this->assertMatchesRegularExpression('/^\d+$/', $output, $errors);
            $allowed += (int) $output;
        }

        // 320 attempts against minute=100 / hourly=150: exactly 100 get through,
        // and the refused 220 are counted in neither window.
        $this->assertSame(100, $allowed);
        $this->assertSame(100, $this->limiter()->countFor(['name', 'concurrency'], 60));
        $this->assertSame(100, $this->limiter()->countFor(['name', 'concurrency'], 3600));
        $this->assertGreaterThan(3500, $this->limiter()->ttlFor(['name', 'concurrency'], 3600));
    }
}
