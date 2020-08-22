<?php

namespace Aporat\RateLimiter\Middleware;

use Aporat\RateLimiter\Facades\RateLimiter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class RateLimit
{

    /**
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {

        if ($request->getClientIp() != null && Str::substr($request->getClientIp(), 0, 5) == '10.0.') {
            return $next($request);
        }

        RateLimiter::withRequest($request)->checkIpAddress();

        if (RateLimiter::getConfigValue('hourly_request_limit') > 0) {
            RateLimiter::withRequest($request)->withClientIpAddress()->withName('requests:hourly')->withTimeInternal(3600)->limit(RateLimiter::getConfigValue('hourly_request_limit'));
        }

        if (RateLimiter::getConfigValue('minute_request_limit') > 0) {
            RateLimiter::withRequest($request)->withClientIpAddress()->withName('requests:minute')->withTimeInternal(60)->limit(RateLimiter::getConfigValue('minute_request_limit'));
        }

        if (RateLimiter::getConfigValue('second_request_limit') > 0) {
            RateLimiter::withRequest($request)->withClientIpAddress()->withName('requests:second')->withTimeInternal(1)->limit(RateLimiter::getConfigValue('second_request_limit'));
        }

        return $next($request);
    }

}
