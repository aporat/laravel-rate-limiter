<?php

declare(strict_types=1);

namespace Aporat\RateLimiter\Middleware;

use Aporat\RateLimiter\Events\RateLimitExceeded;
use Aporat\RateLimiter\Exceptions\RateLimiterUnavailableException;
use Aporat\RateLimiter\Exceptions\RateLimitException;
use Aporat\RateLimiter\RateLimiter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limits requests across one or more fixed windows.
 *
 * Parameters (all optional, Laravel's `alias:param,param` syntax):
 *
 *     rate.limiter                                  windows and key from config
 *     rate.limiter:60,1                             60 requests per 1 minute (like `throttle`)
 *     rate.limiter:minute=60,hourly=1000            explicit windows (second, minute, hourly, daily)
 *     rate.limiter:minute=60,key=user+route,name=api
 *
 * `key` combines `ip`, `user` (authenticated id, falling back to the IP) and
 * `route` (route name or URI pattern) with `+`. `name` gives a group its own
 * counters. When windows are given as parameters, only those windows apply.
 *
 * Every window is checked before any is counted (atomically, in one Redis
 * script), so a refused request does not use up the other windows.
 */
final class RateLimit
{
    /** Window names accepted in config and parameters => length in seconds. */
    public const array WINDOWS = [
        'second' => 1,
        'minute' => 60,
        'hourly' => 3600,
        'hour' => 3600,
        'daily' => 86400,
        'day' => 86400,
    ];

    /** Key types accepted by the `key` option. */
    public const array KEYS = ['ip', 'user', 'route'];

    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * Build a middleware string, e.g. `RateLimit::with(minute: 60, key: 'user')`.
     *
     * @param  string|list<string>|null  $key
     */
    public static function with(
        ?int $second = null,
        ?int $minute = null,
        ?int $hourly = null,
        ?int $daily = null,
        string|array|null $key = null,
        ?string $name = null,
    ): string {
        $parameters = array_filter([
            'second' => $second,
            'minute' => $minute,
            'hourly' => $hourly,
            'daily' => $daily,
            'key' => is_array($key) ? implode('+', $key) : $key,
            'name' => $name,
        ], static fn ($value) => $value !== null);

        $pairs = array_map(static fn (string $option, int|string $value) => "{$option}={$value}", array_keys($parameters), $parameters);

        return $pairs === [] ? 'rate.limiter' : 'rate.limiter:'.implode(',', $pairs);
    }

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws RateLimitException
     * @throws RateLimiterUnavailableException
     */
    public function handle(Request $request, Closure $next, string ...$parameters): Response
    {
        [$windows, $keyTypes, $name] = $this->options(array_values($parameters));

        $ip = $request->getClientIp();

        if ($this->limiter->isWhitelisted($ip)) {
            return $next($request);
        }

        $blockedFor = $this->limiter->blockedFor($ip);

        if ($blockedFor > 0) {
            throw RateLimitException::blocked($blockedFor, $request);
        }

        $segments = $this->segments($request, $keyTypes, $name, $ip);

        // No client IP and nothing else to key on: never lump such requests into
        // one shared bucket; let them through unlimited instead.
        if ($segments === null || $windows === []) {
            return $next($request);
        }

        $result = $this->limiter->attempt($segments, $windows);

        if (! $result->allowed) {
            $this->limiter->dispatch(new RateLimitExceeded($result, $segments, $request));

            throw RateLimitException::fromResult($result, $request);
        }

        $response = $next($request);

        if ((bool) $this->limiter->getConfigValue('headers', true)) {
            $result->applyHeaders($response);
        }

        return $response;
    }

    /**
     * Parse middleware parameters, falling back to config.
     *
     * @param  list<string>  $parameters
     * @return array{0: array<int, int>, 1: list<string>, 2: ?string}
     *
     * @throws InvalidArgumentException On an unknown or malformed parameter
     */
    private function options(array $parameters): array
    {
        $parameters = array_values(array_filter(array_map('trim', $parameters), static fn ($p) => $p !== ''));
        $windows = null;
        $key = null;
        $name = null;

        // Positional shorthand, like Laravel's throttle: maxAttempts,decayMinutes
        if (isset($parameters[0]) && ctype_digit($parameters[0])) {
            $decayMinutes = $parameters[1] ?? '1';

            if (! ctype_digit($decayMinutes) || (int) $decayMinutes < 1) {
                throw new InvalidArgumentException("rate.limiter: decay minutes must be a positive integer, got [{$decayMinutes}].");
            }

            $windows = [(int) $decayMinutes * 60 => (int) $parameters[0]];
            $parameters = array_slice($parameters, isset($parameters[1]) ? 2 : 1);
        }

        foreach ($parameters as $parameter) {
            if (! str_contains($parameter, '=')) {
                throw new InvalidArgumentException("rate.limiter: expected option=value, got [{$parameter}].");
            }

            [$option, $value] = array_map('trim', explode('=', $parameter, 2));

            if (isset(self::WINDOWS[$option])) {
                if (! ctype_digit($value)) {
                    throw new InvalidArgumentException("rate.limiter: [{$option}] must be a non-negative integer, got [{$value}].");
                }

                $windows ??= [];
                $windows[self::WINDOWS[$option]] = (int) $value;
            } elseif ($option === 'key') {
                $key = $value;
            } elseif ($option === 'name') {
                if ($value === '') {
                    throw new InvalidArgumentException('rate.limiter: [name] must not be empty.');
                }

                $name = $value;
            } else {
                throw new InvalidArgumentException("rate.limiter: unknown option [{$option}].");
            }
        }

        $windows ??= $this->configuredWindows();
        $windows = array_filter($windows, static fn (int $limit) => $limit > 0);
        krsort($windows);

        return [$windows, $this->keyTypes($key ?? $this->limiter->getConfigValue('key', 'ip')), $name];
    }

    /**
     * @return array<int, int>
     */
    private function configuredWindows(): array
    {
        $windows = [];

        foreach ((array) $this->limiter->getConfigValue('limits', []) as $option => $limit) {
            if (! is_string($option) || ! isset(self::WINDOWS[$option])) {
                throw new InvalidArgumentException("rate-limiter.limits: unknown window [{$option}].");
            }

            $windows[self::WINDOWS[$option]] = is_numeric($limit) ? (int) $limit : 0;
        }

        return $windows;
    }

    /**
     * @return list<string>
     */
    private function keyTypes(mixed $key): array
    {
        $types = is_string($key) ? array_values(array_unique(array_filter(array_map('trim', explode('+', $key))))) : [];

        if ($types === []) {
            throw new InvalidArgumentException('rate.limiter: [key] must name at least one of ip, user, route.');
        }

        foreach ($types as $type) {
            if (! in_array($type, self::KEYS, true)) {
                throw new InvalidArgumentException("rate.limiter: unknown key type [{$type}]; use ip, user, route.");
            }
        }

        return $types;
    }

    /**
     * @param  list<string>  $keyTypes
     * @return list<string>|null Null when the request cannot be identified
     */
    private function segments(Request $request, array $keyTypes, ?string $name, ?string $ip): ?array
    {
        $segments = $name !== null ? ['name', $name] : [];
        $ipSegments = $ip !== null && $ip !== '' ? ['ip', $this->limiter->ipSubject($ip)] : null;

        foreach ($keyTypes as $type) {
            if ($type === 'route') {
                $route = $request->route();
                $segments[] = 'route';
                $segments[] = $request->getMethod().' '.($route instanceof Route ? ($route->getName() ?? $route->uri()) : $request->getPathInfo());

                continue;
            }

            $userId = $type === 'user' ? $request->user()?->getAuthIdentifier() : null;

            if (is_scalar($userId) && (string) $userId !== '') {
                $segments[] = 'user';
                $segments[] = (string) $userId;
            } elseif ($ipSegments !== null) {
                array_push($segments, ...$ipSegments);
            } else {
                return null;
            }
        }

        return $segments;
    }
}
