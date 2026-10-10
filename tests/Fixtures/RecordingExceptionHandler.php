<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Tests\Fixtures;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Records every exception passed to report() and delegates everything else.
 */
final class RecordingExceptionHandler implements ExceptionHandler
{
    /** @var list<Throwable> */
    public array $reported = [];

    public function __construct(private readonly ExceptionHandler $inner) {}

    public function report(Throwable $e): void
    {
        $this->reported[] = $e;
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        // Not used by the HTTP tests.
    }
}
