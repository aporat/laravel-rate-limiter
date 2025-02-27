<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Exception thrown when a rate limit is exceeded.
 *
 * This exception provides a 429 status code and logs detailed information about the
 * request, including method, URI, server details, and optional debug data.
 */
class RateLimitException extends Exception
{
    /**
     * Whether to include the stack trace in the log report.
     */
    protected bool $traceReporting;

    /**
     * Additional debug information to include in the log.
     */
    protected ?array $debugInfo;

    /**
     * The HTTP request that triggered the exception.
     */
    protected ?Request $request;

    /**
     * Create a new rate limit exception instance.
     *
     * @param  string|null  $message  The exception message (defaults to "Too Many Requests")
     * @param  Request|null  $request  The request triggering the limit
     * @param  array|null  $debugInfo  Additional debug data to log
     * @param  bool  $traceReporting  Whether to log the stack trace
     */
    public function __construct(?string $message = 'Too Many Requests', ?Request $request = null, ?array $debugInfo = null, bool $traceReporting = false)
    {
        parent::__construct($message ?? 'Too Many Requests');

        $this->request = $request;
        $this->debugInfo = $debugInfo;
        $this->traceReporting = $traceReporting;
    }

    /**
     * Get the HTTP status code for the exception.
     */
    public function getStatusCode(): int
    {
        return 429;
    }

    /**
     * Report the exception to the log.
     */
    public function report(): void
    {
        $messageParts = [
            get_class($this).': '.$this->getMessage(),
        ];

        if ($this->request !== null) {
            $messageParts[] = $this->getRequestDescription();
        }

        if ($this->traceReporting) {
            $messageParts[] = $this->getTraceAsString();
        }

        if ($this->debugInfo !== null) {
            $messageParts[] = json_encode($this->debugInfo, JSON_THROW_ON_ERROR);
        }

        Log::error(implode(' ', $messageParts));
    }

    /**
     * Get a descriptive string of the request details.
     */
    private function getRequestDescription(): string
    {
        return sprintf(
            '%s %s %s %s %s',
            $this->request->getMethod(),
            $this->request->getRequestUri(),
            $this->request->server('SERVER_ADDR', 'unknown'),
            json_encode($this->request->all(), JSON_THROW_ON_ERROR),
            json_encode($this->request->header(), JSON_THROW_ON_ERROR)
        );
    }
}
