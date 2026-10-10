<?php

/**
 * Hammers one subject with multi-window attempts from a separate PHP process.
 *
 * Usage: php concurrency-worker.php <client> <host> <port> <db> <attempts>
 * Prints the number of allowed attempts.
 */

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use Aporat\RateLimiter\RateLimiter;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Redis\RedisManager;

[, $client, $host, $port, $database, $attempts] = $argv;

$redis = new RedisManager(new Application(dirname(__DIR__, 2)), $client, [
    'options' => ['prefix' => 'lrltest_'],
    'default' => ['host' => $host, 'port' => (int) $port, 'database' => (int) $database],
]);

$limiter = new RateLimiter(new Repository(['rate-limiter' => ['prefix' => 'rl', 'connection' => 'default']]), $redis);

$allowed = 0;

for ($i = 0; $i < (int) $attempts; $i++) {
    if ($limiter->attempt(['name', 'concurrency'], [3600 => 150, 60 => 100])->allowed) {
        $allowed++;
    }
}

echo $allowed;
