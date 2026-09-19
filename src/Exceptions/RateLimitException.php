<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Exception thrown when a rate limit is exceeded.
 *
 * Implements HttpExceptionInterface so Laravel renders it as a 429 out of the box.
 * Note that it deliberately does NOT extend Symfony's HttpException: that class sits
 * in the framework's internal "don't report" list, which would silence the
 * application's own reporters for this exception.
 *
 * It also deliberately does NOT define a render() method — an exception's own
 * render() wins over the application's `$exceptions->render()` callbacks, so
 * defining one here would take the decision away from the consuming app.
 */
class RateLimitException extends Exception implements HttpExceptionInterface
{
    /**
     * Request headers whose values must never reach the log.
     *
     * @var array<int, string>
     */
    private const array REDACTED_HEADERS = [
        'authorization',
        'cookie',
        'proxy-authorization',
        'x-auth-signature',
        'x-api-key',
        'x-csrf-token',
        'x-xsrf-token',
    ];

    /**
     * Request input keys whose values must never reach the log.
     *
     * @var array<int, string>
     */
    private const array REDACTED_INPUT = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'api_key',
    ];

    /**
     * Create a new rate limit exception instance.
     *
     * @param  string|null  $message  The exception message (defaults to "Too Many Requests")
     * @param  Request|null  $request  The request triggering the limit
     * @param  array<string, mixed>|null  $debugInfo  Additional debug data to log
     * @param  bool  $traceReporting  Whether to log the stack trace
     * @param  int|null  $retryAfter  Seconds until the caller may retry
     */
    public function __construct(
        ?string $message = 'Too Many Requests',
        protected ?Request $request = null,
        protected ?array $debugInfo = null,
        protected bool $traceReporting = false,
        protected ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? 'Too Many Requests', 0, $previous);
    }

    /**
     * Get the HTTP status code for the exception.
     */
    public function getStatusCode(): int
    {
        return 429;
    }

    /**
     * Headers Laravel should attach when rendering this exception.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->retryAfter !== null ? ['Retry-After' => (string) $this->retryAfter] : [];
    }

    /**
     * Seconds until the caller may retry, if known.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * The request that tripped the limit, if one was supplied.
     */
    public function getRequest(): ?Request
    {
        return $this->request;
    }

    /**
     * Extra context recorded at the point the limit was tripped.
     *
     * @return array<string, mixed>|null
     */
    public function getDebugInfo(): ?array
    {
        return $this->debugInfo;
    }

    /**
     * Report the exception to the log.
     *
     * Returns false when the package's own logging is disabled, which tells Laravel
     * to carry on to the application's registered report callbacks. Returning void
     * here would short-circuit them and swallow the event entirely.
     *
     * @return bool True when this exception has been fully reported here
     */
    public function report(): bool
    {
        if (! config('rate-limiter.log_errors', false)) {
            return false;
        }

        $messageParts = [static::class.': '.$this->getMessage()];

        if ($this->request instanceof Request) {
            $messageParts[] = $this->getRequestDescription($this->request);
        }

        if ($this->traceReporting) {
            $messageParts[] = $this->getTraceAsString();
        }

        if ($this->debugInfo !== null) {
            $messageParts[] = $this->encode($this->debugInfo);
        }

        Log::error(implode(' ', $messageParts));

        return true;
    }

    /**
     * Get a descriptive string of the request details, with credentials redacted.
     */
    private function getRequestDescription(Request $request): string
    {
        return sprintf(
            '%s %s %s %s %s',
            $request->getMethod(),
            $request->getRequestUri(),
            (string) $request->server('SERVER_ADDR', 'unknown'),
            $this->encode($this->redact($request->all(), self::REDACTED_INPUT)),
            $this->encode($this->redact($request->headers->all(), self::REDACTED_HEADERS)),
        );
    }

    /**
     * Replace the values of sensitive keys with a placeholder.
     *
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $sensitiveKeys
     * @return array<string, mixed>
     */
    private function redact(array $values, array $sensitiveKeys): array
    {
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value, $sensitiveKeys);
            }
        }

        return $values;
    }

    /**
     * JSON-encode log context without letting an encoding failure mask the limit event.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
