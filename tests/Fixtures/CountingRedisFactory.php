<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests\Fixtures;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;

/**
 * Wraps a Redis factory and counts how often a connection is asked for.
 */
final class CountingRedisFactory implements Factory
{
    public int $calls = 0;

    public function __construct(private readonly Factory $inner) {}

    public function connection($name = null): Connection
    {
        $this->calls++;

        $connection = $this->inner->connection($name);
        assert($connection instanceof Connection);

        return $connection;
    }
}
