<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Events;

use Aporat\RateLimiter\LimitResult;
use Illuminate\Http\Request;

/**
 * Dispatched every time an attempt is refused.
 */
final readonly class RateLimitExceeded
{
    /**
     * @param  list<string>  $segments  The key segments of the refused limit (e.g. ['ip', '203.0.113.7'])
     */
    public function __construct(
        public LimitResult $result,
        public array $segments,
        public ?Request $request = null,
    ) {}
}
