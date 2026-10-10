<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Events;

/**
 * Dispatched when an IP address is blocked with RateLimiter::blockIpAddress().
 */
final readonly class IpBlocked
{
    public function __construct(
        public string $ipAddress,
        public int $seconds,
    ) {}
}
