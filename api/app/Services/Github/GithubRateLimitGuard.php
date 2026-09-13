<?php

namespace App\Services\Github;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GithubRateLimitGuard
{
    private const CACHE_KEY = 'repo-watch:github-rate-limit';

    /**
     * @param  array<string, array<int, string>|string|null>  $headers
     */
    public function rememberFromHeaders(array $headers): void
    {
        $remaining = $this->headerInt($headers, 'X-RateLimit-Remaining');
        $reset = $this->headerInt($headers, 'X-RateLimit-Reset');
        $limit = $this->headerInt($headers, 'X-RateLimit-Limit');

        if ($remaining === null && $reset === null) {
            return;
        }

        Cache::put(self::CACHE_KEY, [
            'remaining' => $remaining,
            'reset' => $reset,
            'limit' => $limit,
            'observed_at' => now()->timestamp,
        ], now()->addHour());
    }

    public function shouldThrottle(): bool
    {
        $state = Cache::get(self::CACHE_KEY);

        if (! is_array($state)) {
            return false;
        }

        $remaining = $state['remaining'] ?? null;
        $reset = $state['reset'] ?? null;
        $floor = (int) config('services.github.repo_watch_rate_limit_floor', 50);

        if (! is_int($remaining) && ! is_numeric($remaining)) {
            return false;
        }

        if ((int) $remaining > $floor) {
            return false;
        }

        if (is_int($reset) || is_numeric($reset)) {
            return now()->timestamp < (int) $reset;
        }

        return true;
    }

    public function secondsUntilReset(): int
    {
        $state = Cache::get(self::CACHE_KEY);

        if (! is_array($state)) {
            return (int) config('services.github.repo_watch_scan_retry_seconds', 300);
        }

        $reset = $state['reset'] ?? null;

        if (! is_int($reset) && ! is_numeric($reset)) {
            return (int) config('services.github.repo_watch_scan_retry_seconds', 300);
        }

        return max(60, (int) $reset - now()->timestamp);
    }

    public function logThrottleDecision(string $context): void
    {
        if (! $this->shouldThrottle()) {
            return;
        }

        Log::warning('GitHub API rate limit floor reached; deferring repo-watch work', [
            'context' => $context,
            'seconds_until_reset' => $this->secondsUntilReset(),
            'state' => Cache::get(self::CACHE_KEY),
        ]);
    }

    /**
     * @param  array<string, array<int, string>|string|null>  $headers
     */
    private function headerInt(array $headers, string $name): ?int
    {
        $value = $headers[$name] ?? $headers[strtolower($name)] ?? null;

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
