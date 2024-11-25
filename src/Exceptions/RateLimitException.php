<?php

namespace Aporat\RateLimiter\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RateLimitException extends Exception
{
    /** @var bool */
    protected bool $traceReporting = true;

    /** @var array|null */
    protected ?array $debugInfo = null;

    /** @var Request|null */
    private ?Request $request;

    /**
     * RateLimitException constructor.
     *
     * @param string|null  $message
     * @param Request|null $request
     * @param array|null   $debugInfo
     * @param bool         $traceReporting
     */
    public function __construct(?string $message, ?Request $request = null, ?array $debugInfo = null, $traceReporting = false)
    {
        parent::__construct($message);

        $this->request = $request;
        $this->traceReporting = $traceReporting;
        $this->debugInfo = $debugInfo;
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return 429;
    }

    /**
     * Report the exception.
     *
     * @return void
     */
    public function report(): void
    {
        $error = get_class($this).': '.$this->getMessage();

        if ($this->request != null) {
            $error .= ' '.$this->getRequestDescription();
        }

        if ($this->traceReporting) {
            $error .= ' '.$this->getTraceAsString();
        }

        if ($this->debugInfo != null) {
            $error .= ' '.json_encode($this->debugInfo);
        }

        Log::error($error);
    }

    /**
     * @return string
     */
    private function getRequestDescription(): string
    {
        return $this->request->getMethod().' '.$this->request->getRequestUri().' '.$this->request->server('SERVER_ADDR').' '.json_encode($this->request->all()).' '.json_encode($this->request->header());
    }
}
