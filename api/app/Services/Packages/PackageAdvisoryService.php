<?php

namespace App\Services\Packages;

use App\Models\Repo\DependencySnapshot;
use App\Models\Repo\PackageAdvisory;
use App\Models\Repo\PackageAdvisoryFinding;
use App\Models\Repo\WatchedRepository;
use App\Services\RepoWatch\HighSignalNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PackageAdvisoryService
{
    public function __construct(
        private readonly HighSignalNotificationService $notificationService,
        private readonly GhsaEnrichmentService $ghsaEnrichment,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.repo_watch.advisory_enabled', true);
    }

    /**
     * @return array{
     *   repositories: int,
     *   packages_queried: int,
     *   advisories_upserted: int,
     *   findings_open: int,
     *   findings_resolved: int,
     *   notifications: int,
     *   ghsa_fetches: int
     * }
     */
    public function refresh(?WatchedRepository $repository = null): array
    {
        if (! $this->enabled()) {
            return [
                'repositories' => 0,
                'packages_queried' => 0,
                'advisories_upserted' => 0,
                'findings_open' => 0,
                'findings_resolved' => 0,
                'notifications' => 0,
                'ghsa_fetches' => 0,
            ];
        }

        $this->ghsaEnrichment->beginRefresh();

        $repositories = $repository instanceof WatchedRepository
            ? collect([$repository])
            : WatchedRepository::query()->orderBy('id')->get();

        $inventory = $this->collectLatestPackageInventory($repositories);

        $queries = $inventory
            ->map(fn (array $row) => [
                'ecosystem' => $row['ecosystem'],
                'package_name' => $row['package_name'],
                'version' => $row['installed_version'],
            ])
            ->unique(fn (array $row) => $this->packageVersionKey(
                $row['ecosystem'],
                $row['package_name'],
                $row['version']
            ))
            ->values();

        $vulnsByKey = $this->queryOsvBatch($queries);

        $advisoriesUpserted = 0;
        $findingsOpen = 0;
        $findingsResolved = 0;
        $notifications = 0;

        foreach ($repositories as $watchedRepository) {
            $repoInventory = $inventory
                ->where('watched_repository_id', $watchedRepository->id)
                ->values();

            $result = $this->syncFindingsForRepository(
                $watchedRepository,
                $repoInventory,
                $vulnsByKey,
            );

            $advisoriesUpserted += $result['advisories_upserted'];
            $findingsOpen += $result['findings_open'];
            $findingsResolved += $result['findings_resolved'];
            $notifications += $result['notifications'];
        }

        return [
            'repositories' => $repositories->count(),
            'packages_queried' => $queries->count(),
            'advisories_upserted' => $advisoriesUpserted,
            'findings_open' => $findingsOpen,
            'findings_resolved' => $findingsResolved,
            'notifications' => $notifications,
            'ghsa_fetches' => $this->ghsaEnrichment->fetchesThisRun(),
        ];
    }

    /**
     * @param  Collection<int, WatchedRepository>  $repositories
     * @return Collection<int, array{
     *   watched_repository_id: int,
     *   dependency_snapshot_id: int,
     *   ecosystem: string,
     *   manifest_path: string,
     *   package_name: string,
     *   installed_version: string,
     *   version_source: ?string
     * }>
     */
    public function collectLatestPackageInventory(Collection $repositories): Collection
    {
        $rows = collect();

        foreach ($repositories as $repository) {
            $latestSnapshots = DependencySnapshot::query()
                ->where('watched_repository_id', $repository->id)
                ->orderByDesc('scanned_at')
                ->orderByDesc('id')
                ->get()
                ->unique(fn (DependencySnapshot $snapshot) => $snapshot->ecosystem.'|'.$snapshot->manifest_path);

            foreach ($latestSnapshots as $snapshot) {
                $packages = $snapshot->packages;
                if (! is_array($packages) || $packages === []) {
                    continue;
                }

                foreach ($packages as $package) {
                    if (! is_array($package)) {
                        continue;
                    }

                    $name = $package['package_name'] ?? null;
                    $version = $package['normalized_current_version'] ?? null;
                    $source = $package['current_version_source'] ?? null;

                    if (! is_string($name) || $name === '' || ! is_string($version) || trim($version) === '') {
                        continue;
                    }

                    // Lock-sourced versions only — manifest constraints are unreliable for OSV.
                    if ($source === 'manifest') {
                        continue;
                    }

                    $rows->push([
                        'watched_repository_id' => $repository->id,
                        'dependency_snapshot_id' => $snapshot->id,
                        'ecosystem' => $snapshot->ecosystem,
                        'manifest_path' => $snapshot->manifest_path,
                        'package_name' => $name,
                        'installed_version' => $version,
                        'version_source' => is_string($source) ? $source : null,
                    ]);
                }
            }
        }

        return $rows->values();
    }

    /**
     * @param  Collection<int, array{ecosystem: string, package_name: string, version: string}>  $queries
     * @return array<string, list<array<string, mixed>>>
     */
    public function queryOsvBatch(Collection $queries): array
    {
        $baseUrl = rtrim((string) config('services.repo_watch.osv_base_url', 'https://api.osv.dev'), '/');
        $chunkSize = max(1, (int) config('services.repo_watch.advisory_query_batch_size', 80));
        $results = [];

        foreach ($queries->chunk($chunkSize) as $chunk) {
            $chunkList = $chunk->values();
            $payload = [
                'queries' => $chunkList->map(fn (array $query) => [
                    'package' => [
                        'name' => $query['package_name'],
                        'ecosystem' => $this->toOsvEcosystem($query['ecosystem']),
                    ],
                    'version' => $query['version'],
                ])->all(),
            ];

            try {
                $response = Http::timeout(20)
                    ->acceptJson()
                    ->asJson()
                    ->post($baseUrl.'/v1/querybatch', $payload);

                if ($response->failed()) {
                    report(new \RuntimeException(
                        'OSV querybatch failed: HTTP '.$response->status()
                    ));

                    continue;
                }

                $batchResults = $response->json('results') ?? [];

                foreach ($chunkList as $index => $query) {
                    $key = $this->packageVersionKey(
                        $query['ecosystem'],
                        $query['package_name'],
                        $query['version']
                    );
                    $vulns = is_array($batchResults[$index]['vulns'] ?? null)
                        ? $batchResults[$index]['vulns']
                        : [];
                    $results[$key] = $vulns;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $results;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $repoInventory
     * @param  array<string, list<array<string, mixed>>>  $vulnsByKey
     * @return array{
     *   advisories_upserted: int,
     *   findings_open: int,
     *   findings_resolved: int,
     *   notifications: int
     * }
     */
    public function syncFindingsForRepository(
        WatchedRepository $repository,
        iterable $repoInventory,
        array $vulnsByKey,
    ): array {
        $repoInventory = collect($repoInventory);
        $now = now();
        $seenFindingIds = [];
        $advisoriesUpserted = 0;
        /** @var list<PackageAdvisoryFinding> $newlyOpenedHighSignal */
        $newlyOpenedHighSignal = [];
        $minSeverity = (string) config('services.repo_watch.advisory_min_severity', 'high');

        foreach ($repoInventory as $row) {
            $key = $this->packageVersionKey(
                $row['ecosystem'],
                $row['package_name'],
                $row['installed_version']
            );

            foreach ($vulnsByKey[$key] ?? [] as $vuln) {
                if (! is_array($vuln) || ! isset($vuln['id']) || ! is_string($vuln['id'])) {
                    continue;
                }

                $advisory = $this->upsertAdvisoryFromOsv(
                    $row['ecosystem'],
                    $row['package_name'],
                    $vuln,
                    $now,
                );
                $advisory = $this->ghsaEnrichment->enrichIfNeeded($advisory);
                $advisoriesUpserted++;

                if ($advisory->withdrawn_at !== null) {
                    continue;
                }

                if (! $this->severityMeetsMinimum($advisory->severity, $minSeverity)) {
                    continue;
                }

                $existing = PackageAdvisoryFinding::query()
                    ->where('watched_repository_id', $repository->id)
                    ->where('package_advisory_id', $advisory->id)
                    ->where('manifest_path', $row['manifest_path'])
                    ->first();

                if ($existing instanceof PackageAdvisoryFinding) {
                    $wasResolved = $existing->status === PackageAdvisoryFinding::STATUS_RESOLVED;
                    $existing->update([
                        'dependency_snapshot_id' => $row['dependency_snapshot_id'],
                        'installed_version' => $row['installed_version'],
                        'status' => PackageAdvisoryFinding::STATUS_OPEN,
                        'last_seen_at' => $now,
                        'resolved_at' => null,
                    ]);
                    $seenFindingIds[] = $existing->id;

                    if ($wasResolved && $advisory->isHighSignal()) {
                        $newlyOpenedHighSignal[] = $existing->fresh(['packageAdvisory']) ?? $existing;
                    }

                    continue;
                }

                $finding = PackageAdvisoryFinding::query()->create([
                    'watched_repository_id' => $repository->id,
                    'package_advisory_id' => $advisory->id,
                    'dependency_snapshot_id' => $row['dependency_snapshot_id'],
                    'ecosystem' => $row['ecosystem'],
                    'manifest_path' => $row['manifest_path'],
                    'package_name' => $row['package_name'],
                    'installed_version' => $row['installed_version'],
                    'status' => PackageAdvisoryFinding::STATUS_OPEN,
                    'first_detected_at' => $now,
                    'last_seen_at' => $now,
                ]);
                $seenFindingIds[] = $finding->id;

                if ($advisory->isHighSignal()) {
                    $newlyOpenedHighSignal[] = $finding->load('packageAdvisory');
                }
            }
        }

        $resolvedQuery = PackageAdvisoryFinding::query()
            ->where('watched_repository_id', $repository->id)
            ->where('status', PackageAdvisoryFinding::STATUS_OPEN);

        if ($repoInventory->isEmpty()) {
            // No lock inventory this run — leave existing findings untouched.
            $resolved = 0;
        } elseif ($seenFindingIds === []) {
            $resolved = $resolvedQuery->update([
                'status' => PackageAdvisoryFinding::STATUS_RESOLVED,
                'resolved_at' => $now,
            ]);
        } else {
            $resolved = $resolvedQuery->whereNotIn('id', $seenFindingIds)->update([
                'status' => PackageAdvisoryFinding::STATUS_RESOLVED,
                'resolved_at' => $now,
            ]);
        }

        $notifications = 0;
        if ($newlyOpenedHighSignal !== []) {
            $created = $this->notificationService->notifyForAdvisories(
                $repository,
                collect($newlyOpenedHighSignal),
            );
            $notifications = count($created);
        }

        return [
            'advisories_upserted' => $advisoriesUpserted,
            'findings_open' => count($seenFindingIds),
            'findings_resolved' => (int) $resolved,
            'notifications' => $notifications,
        ];
    }

    /**
     * @param  array<string, mixed>  $vuln
     */
    public function upsertAdvisoryFromOsv(
        string $ecosystem,
        string $packageName,
        array $vuln,
        mixed $fetchedAt = null,
    ): PackageAdvisory {
        $fetchedAt ??= now();
        $summary = null;
        if (isset($vuln['summary']) && is_string($vuln['summary'])) {
            $summary = Str::limit($vuln['summary'], 500);
        } elseif (isset($vuln['details']) && is_string($vuln['details'])) {
            $summary = Str::limit($vuln['details'], 500);
        }

        return PackageAdvisory::query()->updateOrCreate(
            [
                'source' => PackageAdvisory::SOURCE_OSV,
                'advisory_id' => (string) $vuln['id'],
                'ecosystem' => $ecosystem,
                'package_name' => $packageName,
            ],
            [
                'severity' => $this->mapOsvSeverity($vuln),
                'summary' => $summary,
                'aliases' => isset($vuln['aliases']) && is_array($vuln['aliases']) ? $vuln['aliases'] : [],
                'affected_ranges' => $vuln['affected'] ?? null,
                'fixed_version' => $this->extractFixedVersion($vuln, $ecosystem, $packageName),
                'reference_url' => $this->pickReferenceUrl($vuln),
                'published_at' => $vuln['published'] ?? null,
                'withdrawn_at' => $vuln['withdrawn'] ?? null,
                'last_fetched_at' => $fetchedAt,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $vuln
     */
    public function mapOsvSeverity(array $vuln): string
    {
        $scores = [];

        if (isset($vuln['severity']) && is_array($vuln['severity'])) {
            foreach ($vuln['severity'] as $entry) {
                if (is_array($entry) && isset($entry['score']) && is_numeric($entry['score'])) {
                    $scores[] = (float) $entry['score'];
                }
            }
        }

        $databaseSpecific = $vuln['database_specific'] ?? [];
        if (is_array($databaseSpecific)) {
            foreach (['severity', 'github_reviewed_severity', 'cvss_score'] as $key) {
                if (! isset($databaseSpecific[$key])) {
                    continue;
                }
                $value = $databaseSpecific[$key];
                if (is_numeric($value)) {
                    $scores[] = (float) $value;
                }
                if (is_string($value)) {
                    $normalized = strtolower($value);
                    if ($normalized === 'medium') {
                        return PackageAdvisory::SEVERITY_MODERATE;
                    }
                    if (in_array($normalized, [
                        PackageAdvisory::SEVERITY_CRITICAL,
                        PackageAdvisory::SEVERITY_HIGH,
                        PackageAdvisory::SEVERITY_MODERATE,
                        PackageAdvisory::SEVERITY_LOW,
                    ], true)) {
                        return $normalized;
                    }
                }
            }
        }

        if ($scores !== []) {
            $max = max($scores);
            if ($max >= 9.0) {
                return PackageAdvisory::SEVERITY_CRITICAL;
            }
            if ($max >= 7.0) {
                return PackageAdvisory::SEVERITY_HIGH;
            }
            if ($max >= 4.0) {
                return PackageAdvisory::SEVERITY_MODERATE;
            }
            if ($max > 0) {
                return PackageAdvisory::SEVERITY_LOW;
            }
        }

        return PackageAdvisory::SEVERITY_UNKNOWN;
    }

    public function toOsvEcosystem(string $ecosystem): string
    {
        return match (strtolower($ecosystem)) {
            'composer' => 'Packagist',
            'npm' => 'npm',
            default => $ecosystem,
        };
    }

    public function severityMeetsMinimum(string $severity, string $minimum): bool
    {
        $rank = [
            PackageAdvisory::SEVERITY_CRITICAL => 4,
            PackageAdvisory::SEVERITY_HIGH => 3,
            PackageAdvisory::SEVERITY_MODERATE => 2,
            PackageAdvisory::SEVERITY_LOW => 1,
            PackageAdvisory::SEVERITY_UNKNOWN => 0,
        ];

        $minimum = $minimum === 'medium' ? PackageAdvisory::SEVERITY_MODERATE : $minimum;

        return ($rank[$severity] ?? 0) >= ($rank[$minimum] ?? 3);
    }

    public function packageVersionKey(string $ecosystem, string $packageName, string $version): string
    {
        return strtolower($ecosystem).'|'.strtolower($packageName).'|'.$version;
    }

    /**
     * @param  array<string, mixed>  $vuln
     */
    private function pickReferenceUrl(array $vuln): ?string
    {
        $references = $vuln['references'] ?? [];
        if (! is_array($references)) {
            return null;
        }

        foreach ($references as $reference) {
            if (is_array($reference)
                && ($reference['type'] ?? null) === 'ADVISORY'
                && isset($reference['url'])
                && is_string($reference['url'])
            ) {
                return $reference['url'];
            }
        }

        foreach ($references as $reference) {
            if (is_array($reference) && isset($reference['url']) && is_string($reference['url'])) {
                return $reference['url'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $vuln
     */
    private function extractFixedVersion(array $vuln, string $ecosystem, string $packageName): ?string
    {
        $affected = $vuln['affected'] ?? [];
        if (! is_array($affected)) {
            return null;
        }

        $osvEcosystem = $this->toOsvEcosystem($ecosystem);

        foreach ($affected as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $pkg = $entry['package'] ?? [];
            if (! is_array($pkg)
                || ($pkg['name'] ?? null) !== $packageName
                || ($pkg['ecosystem'] ?? null) !== $osvEcosystem
            ) {
                continue;
            }

            foreach ($entry['ranges'] ?? [] as $range) {
                if (! is_array($range)) {
                    continue;
                }
                foreach ($range['events'] ?? [] as $event) {
                    if (is_array($event) && isset($event['fixed']) && is_string($event['fixed'])) {
                        return $event['fixed'];
                    }
                }
            }
        }

        return null;
    }
}
