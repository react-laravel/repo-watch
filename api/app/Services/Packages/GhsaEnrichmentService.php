<?php

namespace App\Services\Packages;

use App\Models\Repo\PackageAdvisory;
use App\Services\Github\GithubRateLimitGuard;
use App\Services\Github\GithubRepositoryWatcherService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Optional secondary enrichment of OSV advisories via GitHub Global Advisories.
 * Never required for OSV to work; skipped without PAT / when rate-limited.
 */
class GhsaEnrichmentService
{
    private int $fetchesThisRun = 0;

    public function __construct(
        private readonly GithubRepositoryWatcherService $github,
        private readonly GithubRateLimitGuard $rateLimitGuard,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.repo_watch.ghsa_enrichment_enabled', false);
    }

    public function beginRefresh(): void
    {
        $this->fetchesThisRun = 0;
    }

    public function fetchesThisRun(): int
    {
        return $this->fetchesThisRun;
    }

    public function enrichIfNeeded(PackageAdvisory $advisory): PackageAdvisory
    {
        if (! $this->enabled()) {
            return $advisory;
        }

        $token = config('services.github.token');
        if (! is_string($token) || trim($token) === '') {
            return $advisory;
        }

        $ghsaId = $this->resolveGhsaId($advisory);
        if ($ghsaId === null) {
            return $advisory;
        }

        // Always try cache (or a budgeted network fetch). Re-apply after each OSV
        // upsert so severity/summary/permalink are not wiped by the primary path.
        $payload = $this->fetchAdvisory($ghsaId);
        if ($payload === null) {
            return $advisory;
        }

        return $this->applyEnrichment($advisory, $ghsaId, $payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAdvisory(string $ghsaId): ?array
    {
        $ttl = max(300, (int) config('services.repo_watch.ghsa_enrichment_cache_ttl', 86400));
        $cacheKey = 'repo-watch:ghsa:'.$ghsaId;

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $maxPerRefresh = max(0, (int) config('services.repo_watch.ghsa_enrichment_max_per_refresh', 20));
        if ($this->fetchesThisRun >= $maxPerRefresh) {
            return null;
        }

        if ($this->rateLimitGuard->shouldThrottle()) {
            $this->rateLimitGuard->logThrottleDecision('ghsa-enrichment');

            return null;
        }

        try {
            $this->fetchesThisRun++;
            $response = $this->github->githubApi()
                ->get('https://api.github.com/advisories/'.rawurlencode($ghsaId));

            $this->rateLimitGuard->rememberFromHeaders($response->headers());

            if ($response->status() === 404) {
                $missing = ['__missing' => true];
                Cache::put($cacheKey, $missing, now()->addSeconds(min($ttl, 3600)));

                return $missing;
            }

            if ($response->failed()) {
                Log::warning('GHSA enrichment request failed', [
                    'ghsa_id' => $ghsaId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $json = $response->json();
            if (! is_array($json)) {
                return null;
            }

            Cache::put($cacheKey, $json, now()->addSeconds($ttl));

            return $json;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyEnrichment(PackageAdvisory $advisory, string $ghsaId, array $payload): PackageAdvisory
    {
        if (($payload['__missing'] ?? false) === true) {
            $advisory->fill([
                'ghsa_id' => $ghsaId,
                'ghsa_enriched_at' => now(),
            ]);
            $advisory->save();

            return $advisory->refresh();
        }

        $aliases = [];
        foreach ($advisory->aliases ?? [] as $alias) {
            if (is_string($alias) && $alias !== '') {
                $aliases[] = $alias;
            }
        }
        if (! in_array($ghsaId, $aliases, true)) {
            $aliases[] = $ghsaId;
        }

        foreach (($payload['identifiers'] ?? []) as $identifier) {
            if (! is_array($identifier)) {
                continue;
            }
            $value = $identifier['value'] ?? null;
            if (is_string($value) && $value !== '' && ! in_array($value, $aliases, true)) {
                $aliases[] = $value;
            }
        }

        $ghsaSeverity = $this->mapGithubSeverity(
            is_string($payload['severity'] ?? null) ? $payload['severity'] : null
        );

        $summary = $advisory->summary;
        if (isset($payload['summary']) && is_string($payload['summary']) && trim($payload['summary']) !== '') {
            $summary = Str::limit($payload['summary'], 500);
        }

        $referenceUrl = $advisory->reference_url;
        if (isset($payload['html_url']) && is_string($payload['html_url']) && $payload['html_url'] !== '') {
            $referenceUrl = $payload['html_url'];
        } else {
            $referenceUrl = 'https://github.com/advisories/'.$ghsaId;
        }

        $advisory->fill([
            'ghsa_id' => $ghsaId,
            'ghsa_enriched_at' => now(),
            'severity' => $ghsaSeverity !== PackageAdvisory::SEVERITY_UNKNOWN
                ? $ghsaSeverity
                : $advisory->severity,
            'summary' => $summary,
            'aliases' => $aliases,
            'reference_url' => $referenceUrl,
            'published_at' => $payload['published_at'] ?? $advisory->published_at,
            'withdrawn_at' => $payload['withdrawn_at'] ?? $advisory->withdrawn_at,
        ]);
        $advisory->save();

        return $advisory->refresh();
    }

    public function resolveGhsaId(PackageAdvisory $advisory): ?string
    {
        if (is_string($advisory->ghsa_id) && $this->isGhsaId($advisory->ghsa_id)) {
            return strtoupper($advisory->ghsa_id);
        }

        if ($this->isGhsaId($advisory->advisory_id)) {
            return strtoupper($advisory->advisory_id);
        }

        foreach ($advisory->aliases ?? [] as $alias) {
            if (is_string($alias) && $this->isGhsaId($alias)) {
                return strtoupper($alias);
            }
        }

        return null;
    }

    public function isGhsaId(string $value): bool
    {
        return (bool) preg_match('/^GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/i', $value);
    }

    public function mapGithubSeverity(?string $severity): string
    {
        return match (strtolower((string) $severity)) {
            'critical' => PackageAdvisory::SEVERITY_CRITICAL,
            'high' => PackageAdvisory::SEVERITY_HIGH,
            'medium', 'moderate' => PackageAdvisory::SEVERITY_MODERATE,
            'low' => PackageAdvisory::SEVERITY_LOW,
            default => PackageAdvisory::SEVERITY_UNKNOWN,
        };
    }
}
