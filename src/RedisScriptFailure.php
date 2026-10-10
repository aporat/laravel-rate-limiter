<?php

declare(strict_types=1);

namespace Aporat\RateLimiter;

use RuntimeException;

/**
 * Redis answered the limiter script with an error (treated like Redis being unavailable).
 *
 * @internal
 */
final class RedisScriptFailure extends RuntimeException {}
