# Changelog

## [6.0.0] - Unreleased

Breaking release; see "Upgrading from v5" in the README.

### Fixed

- **Refused requests no longer use up the other windows.** Every window is checked, and the request is counted in all of them only if all allow it, in one atomic Lua script. Previously a burst refused by the per-second window still incremented the hourly counter, locking a client out for the hour.
- The default whitelist no longer includes `10.0.0.0/8`. Behind a load balancer it disabled limiting entirely; with trust-all proxies, any client could claim a private address.
- A named Redis `connection` is honored. The package's host, port and database defaults used to override it.
- Rate limit headers describe the most restrictive window. Previously they always described the per-second window.
- 429 responses carry `X-RateLimit-*` and `Retry-After`; blocked IPs get `Retry-After` set to the block TTL.
- The facade no longer inherits state from a previous call or from the middleware: builders are immutable.
- Amounts, limits, intervals and block durations below 1 are rejected (a negative amount used to decrement the counter).
- Key segments are escaped, so `withUserId('a:b')` no longer collides with `withUserId('a')->withName('b')`, nor `/a/b` with `/a:b`.
- Middleware parameters are parsed and validated instead of silently ignored.
- Requests without a client IP are no longer counted in one shared bucket.
- Logging no longer writes every 429 at error level with input and headers. When enabled, it writes one warning with the path only.
- Config is read through the `Illuminate\Contracts\Config\Repository` contract, and the singleton holds no request state (Octane).
- The script runs with EVALSHA, falling back to EVAL on NOSCRIPT.

### Added

- Laravel Redis manager support: phpredis or predis, cluster (all windows of a subject share a hash tag), TLS, `REDIS_URL`.
- Middleware parameters: `rate.limiter:60,1` and `rate.limiter:second=,minute=,hourly=,daily=,key=ip|user|route (combine with +),name=`, plus `RateLimit::with()`.
- `on_redis_failure` (`open` / `closed` → 503) with `failure_backoff_seconds`; Redis errors are reported once per backoff.
- `X-RateLimit-Reset` header.
- `RateLimitExceeded` and `IpBlocked` events.
- `PendingLimit::attempt()`, `LimitResult`, `RateLimiter::attempt()` for several windows at once, and `blockedFor()`.

### Changed

- PHP 8.3+. `ext-redis` is optional (phpredis or predis).
- Headers renamed to `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`.
- Defaults: whitelist loopback only, `log_errors` false, `headers` true.
- CI:
  - Each lane installs the Laravel version it names.
  - PHP 8.3, 8.4 and 8.5 lanes.
  - Tests run with both Redis clients.
  - Pint and PHPStan (level 8) run as separate jobs.
  - The service image is `redis:8`.

### Removed

- `withRateLimitHeaders()`, `setRequestTag()`, `getRequestTag()`, `checkIpAddress()`, the `redis.*` connection settings, the `ext-json` requirement and Lumen references.
