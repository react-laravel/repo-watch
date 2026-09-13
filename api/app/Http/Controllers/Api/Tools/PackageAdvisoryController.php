<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Models\Repo\PackageAdvisory;
use App\Models\Repo\PackageAdvisoryFinding;
use App\Models\Repo\WatchedRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PackageAdvisoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'repository_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ecosystem' => ['sometimes', 'nullable', 'string', Rule::in(['npm', 'composer'])],
            'severity' => ['sometimes', 'nullable', 'string', Rule::in([
                PackageAdvisory::SEVERITY_CRITICAL,
                PackageAdvisory::SEVERITY_HIGH,
                PackageAdvisory::SEVERITY_MODERATE,
                PackageAdvisory::SEVERITY_LOW,
                PackageAdvisory::SEVERITY_UNKNOWN,
            ])],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([
                PackageAdvisoryFinding::STATUS_OPEN,
                PackageAdvisoryFinding::STATUS_RESOLVED,
            ])],
            'include_muted' => ['sometimes', 'boolean'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);
        $repositoryId = isset($validated['repository_id']) ? (int) $validated['repository_id'] : null;
        $ecosystem = $validated['ecosystem'] ?? null;
        $severity = $validated['severity'] ?? null;
        $status = $validated['status'] ?? PackageAdvisoryFinding::STATUS_OPEN;
        $includeMuted = filter_var($validated['include_muted'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $ownedRepositoryIds = WatchedRepository::query()
            ->where('user_id', $request->user()->id)
            ->when(
                $repositoryId !== null,
                fn ($query) => $query->where('id', $repositoryId)
            )
            // Explicit repository_id always wins so a muted repo can still be inspected.
            ->when(
                $repositoryId === null && ! $includeMuted,
                fn ($query) => $query->whereNull('muted_at')
            )
            ->pluck('id');

        if ($ownedRepositoryIds->isEmpty()) {
            return $this->success([
                'findings' => [],
                'policy' => $this->policy(),
            ]);
        }

        $findings = PackageAdvisoryFinding::query()
            ->with(['packageAdvisory', 'watchedRepository'])
            ->whereIn('watched_repository_id', $ownedRepositoryIds)
            ->when(
                is_string($status) && $status !== '',
                fn ($query) => $query->where('status', $status)
            )
            ->when(
                is_string($ecosystem) && $ecosystem !== '',
                fn ($query) => $query->where('ecosystem', $ecosystem)
            )
            ->when(
                is_string($severity) && $severity !== '',
                fn ($query) => $query->whereHas(
                    'packageAdvisory',
                    fn ($advisoryQuery) => $advisoryQuery->where('severity', $severity)
                )
            )
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (PackageAdvisoryFinding $finding) => $this->transform($finding));

        return $this->success([
            'findings' => $findings->all(),
            'policy' => $this->policy(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(PackageAdvisoryFinding $finding): array
    {
        $repo = $finding->watchedRepository;
        $advisory = $finding->packageAdvisory;

        return [
            'id' => $finding->id,
            'watched_repository_id' => $finding->watched_repository_id,
            'repository' => $repo instanceof WatchedRepository ? [
                'id' => $repo->id,
                'full_name' => $repo->displayName(),
                'owner' => $repo->owner,
                'repo' => $repo->repo,
                'url' => $repo->url,
                'muted' => $repo->isMuted(),
            ] : null,
            'ecosystem' => $finding->ecosystem,
            'manifest_path' => $finding->manifest_path,
            'package_name' => $finding->package_name,
            'installed_version' => $finding->installed_version,
            'status' => $finding->status,
            'first_detected_at' => $finding->first_detected_at,
            'last_seen_at' => $finding->last_seen_at,
            'resolved_at' => $finding->resolved_at,
            'advisory' => $advisory instanceof PackageAdvisory ? [
                'id' => $advisory->id,
                'source' => $advisory->source,
                'advisory_id' => $advisory->advisory_id,
                'ghsa_id' => $advisory->ghsa_id,
                'ghsa_enriched_at' => $advisory->ghsa_enriched_at,
                'severity' => $advisory->severity,
                'summary' => $advisory->summary,
                'aliases' => $advisory->aliases,
                'fixed_version' => $advisory->fixed_version,
                'reference_url' => $advisory->reference_url,
                'published_at' => $advisory->published_at,
            ] : null,
        ];
    }

    /**
     * @return array{
     *   enabled: bool,
     *   min_severity: string,
     *   notify_on_advisory: bool,
     *   osv_base_url: string,
     *   ghsa_enrichment_enabled: bool,
     *   ghsa_enrichment_cache_ttl: int,
     *   ghsa_enrichment_max_per_refresh: int
     * }
     */
    private function policy(): array
    {
        return [
            'enabled' => (bool) config('services.repo_watch.advisory_enabled', true),
            'min_severity' => (string) config('services.repo_watch.advisory_min_severity', 'high'),
            'notify_on_advisory' => (bool) config('services.repo_watch.notify_on_advisory', true),
            'osv_base_url' => (string) config('services.repo_watch.osv_base_url', 'https://api.osv.dev'),
            'ghsa_enrichment_enabled' => (bool) config('services.repo_watch.ghsa_enrichment_enabled', false),
            'ghsa_enrichment_cache_ttl' => (int) config('services.repo_watch.ghsa_enrichment_cache_ttl', 86400),
            'ghsa_enrichment_max_per_refresh' => (int) config('services.repo_watch.ghsa_enrichment_max_per_refresh', 20),
        ];
    }
}
