<?php

namespace App\Services\Github;

use App\Models\Repo\DependencyChange;
use App\Models\Repo\DependencySnapshot;
use App\Models\Repo\WatchedPackage;
use App\Models\Repo\WatchedRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RepositoryDependencyScanService
{
    public function __construct(
        private readonly GithubDependencyScannerService $scannerService,
        private readonly GithubRateLimitGuard $rateLimitGuard,
    ) {}

    /**
     * @return array{
     *   repository: WatchedRepository,
     *   snapshots_created: int,
     *   changes_detected: int,
     *   deferred: bool
     * }
     */
    public function scan(WatchedRepository $repository, bool $force = false): array
    {
        $lock = Cache::lock("repo-watch:repo-scan:{$repository->id}", 180);

        if (! $lock->get()) {
            return [
                'repository' => $repository->fresh() ?? $repository,
                'snapshots_created' => 0,
                'changes_detected' => 0,
                'deferred' => true,
            ];
        }

        try {
            if (! $force && $this->rateLimitGuard->shouldThrottle()) {
                $this->rateLimitGuard->logThrottleDecision("scan:{$repository->id}");
                $repository->update([
                    'scan_status' => WatchedRepository::STATUS_PENDING,
                    'next_scan_at' => now()->addSeconds($this->rateLimitGuard->secondsUntilReset()),
                    'last_scan_error' => 'Deferred due to GitHub API rate limit floor',
                ]);

                return [
                    'repository' => $repository->fresh() ?? $repository,
                    'snapshots_created' => 0,
                    'changes_detected' => 0,
                    'deferred' => true,
                ];
            }

            $repository->update([
                'scan_status' => WatchedRepository::STATUS_SCANNING,
                'last_scan_error' => null,
            ]);

            $preview = $this->fetchPreview($repository);
            $timestamp = now();
            $snapshotsCreated = 0;
            $changesDetected = 0;
            $totalPackages = 0;

            DB::transaction(function () use (
                $repository,
                $preview,
                $timestamp,
                &$snapshotsCreated,
                &$changesDetected,
                &$totalPackages,
            ): void {
                foreach ($preview['manifests'] as $manifest) {
                    $packages = collect($manifest['dependencies'] ?? [])
                        ->map(fn (array $dependency) => [
                            'package_name' => $dependency['package_name'],
                            'current_version_constraint' => $dependency['current_version_constraint'] ?? null,
                            'normalized_current_version' => $dependency['normalized_current_version'] ?? null,
                            'current_version_source' => $dependency['current_version_source'] ?? null,
                            'dependency_group' => $dependency['dependency_group'] ?? null,
                        ])
                        ->sortBy('package_name')
                        ->values()
                        ->all();

                    $hash = hash('sha256', json_encode($packages, JSON_THROW_ON_ERROR));
                    $totalPackages += count($packages);

                    /** @var DependencySnapshot|null $previous */
                    $previous = DependencySnapshot::query()
                        ->where('watched_repository_id', $repository->id)
                        ->where('ecosystem', $manifest['ecosystem'])
                        ->where('manifest_path', $manifest['path'])
                        ->orderByDesc('scanned_at')
                        ->orderByDesc('id')
                        ->first();

                    if ($previous && $previous->packages_hash === $hash) {
                        continue;
                    }

                    $snapshot = DependencySnapshot::query()->create([
                        'watched_repository_id' => $repository->id,
                        'ecosystem' => $manifest['ecosystem'],
                        'manifest_path' => $manifest['path'],
                        'packages_hash' => $hash,
                        'packages' => $packages,
                        'package_count' => count($packages),
                        'scanned_at' => $timestamp,
                    ]);
                    $snapshotsCreated++;

                    if ($previous) {
                        $changesDetected += $this->recordChanges(
                            $repository,
                            $snapshot,
                            $previous->packages ?? [],
                            $packages,
                            $timestamp
                        );
                    }

                    $this->syncWatchedPackageBaselines($repository, $manifest['ecosystem'], $manifest['path'], $packages);
                }

                $intervalHours = (int) config('services.github.repo_watch_scan_interval_hours', 6);

                $repository->update([
                    'full_name' => Arr::get($preview, 'source.full_name', $repository->displayName()),
                    'description' => Arr::get($preview, 'source.description'),
                    'scan_status' => WatchedRepository::STATUS_IDLE,
                    'last_scanned_at' => $timestamp,
                    'next_scan_at' => $timestamp->copy()->addHours($intervalHours),
                    'last_scan_error' => null,
                    'package_count' => $totalPackages > 0
                        ? $totalPackages
                        : $repository->watchedPackages()->count(),
                    'metadata' => array_merge($repository->metadata ?? [], [
                        'last_preview_source' => Arr::get($preview, 'source'),
                    ]),
                ]);
            });

            return [
                'repository' => $repository->fresh() ?? $repository,
                'snapshots_created' => $snapshotsCreated,
                'changes_detected' => $changesDetected,
                'deferred' => false,
            ];
        } catch (Throwable $exception) {
            $repository->update([
                'scan_status' => WatchedRepository::STATUS_ERROR,
                'last_scan_error' => Str::limit($exception->getMessage(), 1000),
                'next_scan_at' => now()->addMinutes(30),
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function ensureForUser(
        int $userId,
        string $owner,
        string $repo,
        string $url,
        ?string $description = null,
    ): WatchedRepository {
        $normalizedOwner = Str::lower($owner);
        $normalizedRepo = Str::lower($repo);

        return WatchedRepository::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'provider' => 'github',
                'owner' => $normalizedOwner,
                'repo' => $normalizedRepo,
            ],
            [
                'url' => $url,
                'full_name' => "{$normalizedOwner}/{$normalizedRepo}",
                'description' => $description,
                'scan_status' => WatchedRepository::STATUS_PENDING,
                'next_scan_at' => now(),
            ]
        );
    }

    /**
     * @return array{
     *   source: array<string, mixed>,
     *   manifests: array<int, array{ecosystem: string, path: string, package_name: ?string, dependencies: array<int, array<string, mixed>>}>
     * }
     */
    private function fetchPreview(WatchedRepository $repository): array
    {
        // Bypass the short-lived preview cache so scheduled scans always observe current manifests.
        Cache::forget(sprintf(
            'repo-watch:preview:%s/%s',
            strtolower($repository->owner),
            strtolower($repository->repo)
        ));

        return $this->scannerService->previewDependencies($repository->url);
    }

    /**
     * @param  array<int, array<string, mixed>>  $previousPackages
     * @param  array<int, array<string, mixed>>  $currentPackages
     */
    private function recordChanges(
        WatchedRepository $repository,
        DependencySnapshot $snapshot,
        array $previousPackages,
        array $currentPackages,
        mixed $timestamp,
    ): int {
        $previousByName = collect($previousPackages)->keyBy('package_name');
        $currentByName = collect($currentPackages)->keyBy('package_name');
        $rows = [];

        foreach ($currentByName as $name => $current) {
            $previous = $previousByName->get($name);

            if ($previous === null) {
                $rows[] = [
                    'watched_repository_id' => $repository->id,
                    'dependency_snapshot_id' => $snapshot->id,
                    'ecosystem' => $snapshot->ecosystem,
                    'manifest_path' => $snapshot->manifest_path,
                    'package_name' => $name,
                    'change_type' => DependencyChange::TYPE_ADDED,
                    'previous_constraint' => null,
                    'new_constraint' => $current['current_version_constraint'] ?? null,
                    'previous_version' => null,
                    'new_version' => $current['normalized_current_version'] ?? null,
                    'detected_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                continue;
            }

            $previousConstraint = $previous['current_version_constraint'] ?? null;
            $newConstraint = $current['current_version_constraint'] ?? null;
            $previousVersion = $previous['normalized_current_version'] ?? null;
            $newVersion = $current['normalized_current_version'] ?? null;

            if ($previousConstraint === $newConstraint && $previousVersion === $newVersion) {
                continue;
            }

            $rows[] = [
                'watched_repository_id' => $repository->id,
                'dependency_snapshot_id' => $snapshot->id,
                'ecosystem' => $snapshot->ecosystem,
                'manifest_path' => $snapshot->manifest_path,
                'package_name' => $name,
                'change_type' => DependencyChange::TYPE_UPDATED,
                'previous_constraint' => $previousConstraint,
                'new_constraint' => $newConstraint,
                'previous_version' => $previousVersion,
                'new_version' => $newVersion,
                'detected_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach ($previousByName as $name => $previous) {
            if ($currentByName->has($name)) {
                continue;
            }

            $rows[] = [
                'watched_repository_id' => $repository->id,
                'dependency_snapshot_id' => $snapshot->id,
                'ecosystem' => $snapshot->ecosystem,
                'manifest_path' => $snapshot->manifest_path,
                'package_name' => $name,
                'change_type' => DependencyChange::TYPE_REMOVED,
                'previous_constraint' => $previous['current_version_constraint'] ?? null,
                'new_constraint' => null,
                'previous_version' => $previous['normalized_current_version'] ?? null,
                'new_version' => null,
                'detected_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($rows !== []) {
            DependencyChange::query()->insert($rows);
        }

        return count($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $packages
     */
    private function syncWatchedPackageBaselines(
        WatchedRepository $repository,
        string $ecosystem,
        string $manifestPath,
        array $packages,
    ): void {
        $packagesByName = collect($packages)->keyBy('package_name');

        WatchedPackage::query()
            ->where('watched_repository_id', $repository->id)
            ->where('ecosystem', $ecosystem)
            ->where('manifest_path', $manifestPath)
            ->get()
            ->each(function (WatchedPackage $watchedPackage) use ($packagesByName): void {
                $snapshotPackage = $packagesByName->get($watchedPackage->package_name);

                if ($snapshotPackage === null) {
                    return;
                }

                $watchedPackage->update([
                    'current_version_constraint' => $snapshotPackage['current_version_constraint'] ?? $watchedPackage->current_version_constraint,
                    'normalized_current_version' => $snapshotPackage['normalized_current_version'] ?? $watchedPackage->normalized_current_version,
                    'metadata' => array_merge($watchedPackage->metadata ?? [], [
                        'dependency_group' => $snapshotPackage['dependency_group']
                            ?? Arr::get($watchedPackage->metadata ?? [], 'dependency_group'),
                        'current_version_source' => $snapshotPackage['current_version_source']
                            ?? Arr::get($watchedPackage->metadata ?? [], 'current_version_source'),
                    ]),
                ]);
            });
    }
}
