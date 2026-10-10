<?php

declare(strict_types=1);

namespace Aporat\RateLimiter;

/**
 * The state of one fixed window after an attempt.
 */
final readonly class WindowState
{
    /**
     * @param  int  $seconds  Window length
     * @param  int  $limit  Maximum hits allowed in the window
     * @param  int  $count  Hits recorded in the window (after this attempt when it was allowed)
     * @param  int  $resetsInMs  Milliseconds until the window resets
     * @param  bool  $exceeded  Whether this window refused the attempt
     */
    public function __construct(
        public int $seconds,
        public int $limit,
        public int $count,
        public int $resetsInMs,
        public bool $exceeded = false,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->limit - $this->count);
    }

    /**
     * Whole seconds until the window resets, rounded up (never 0 for a live window).
     */
    public function resetsIn(): int
    {
        return max(1, (int) ceil($this->resetsInMs / 1000));
    }
}
