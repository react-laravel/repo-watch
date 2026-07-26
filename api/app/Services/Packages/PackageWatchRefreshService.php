<?php

namespace App\Services\Packages;

use App\Models\Repo\RegistryPackage;
use App\Models\Repo\WatchedPackage;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class PackageWatchRefreshService
{
    public function __construct(
        private readonly PackageRegistryService $registryService
    ) {}

    public function refreshPackage(WatchedPackage $watchedPackage): WatchedPackage
    {
        $registryPackage = $this->registryPackageFor($watchedPackage);
        $this->refreshRegistryPackageIds([$registryPackage->id], onlyStale: true);

        return $watchedPackage->fresh('registryPackage');
    }

    public function refreshRepositoryPackages(string $owner, string $repo): int
    {
        return $this->refreshRegistryPackageIds(
            $this->registryPackageIdsForRepository($owner, $repo)
        );
    }

    /**
     * @return array<int, int>
     */
    public function registryPackageIdsForRepository(string $owner, string $repo): array
    {
        $normalizedOwner = Str::lower($owner);
        $normalizedRepo = Str::lower($repo);

        /** @var Collection<int, WatchedPackage> $packages */
        $packages = WatchedPackage::query()
            ->where('source_provider', 'github')
            ->whereRaw('LOWER(source_owner) = ?', [$normalizedOwner])
            ->whereRaw('LOWER(source_repo) = ?', [$normalizedRepo])
            ->get();

        $this->ensureRegistryPackages($packages);

        return $packages->pluck('registry_package_id')->filter()->unique()->values()->all();
    }

    /**
     * Compatibility path for jobs queued before registry packages became shared.
     *
     * @param  array<int, int>  $packageIds
     */
    public function refreshPackageIds(int $userId, array $packageIds): int
    {
        /** @var Collection<int, WatchedPackage> $packages */
        $packages = WatchedPackage::query()
            ->where('user_id', $userId)
            ->whereIn('id', $packageIds)
            ->get();

        $this->ensureRegistryPackages($packages);

        return $this->refreshRegistryPackageIds(
            $packages->pluck('registry_package_id')->filter()->unique()->values()->all()
        );
    }

    /**
     * @param  array<int, int>  $registryPackageIds
     */
    public function refreshRegistryPackageIds(
        array $registryPackageIds,
        bool $onlyStale = false,
        ?int $staleHours = null
    ): int {
        $query = RegistryPackage::query()
            ->whereIn('id', array_values(array_unique($registryPackageIds)));

        if ($onlyStale) {
            $hours = $staleHours ?? (int) config('services.github.repo_watch_refresh_hours', 6);
            $query->where(function ($query) use ($hours) {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<=', now()->subHours($hours));
            });
        }

        /** @var Collection<int, RegistryPackage> $registryPackages */
        $registryPackages = $query->get();

        /** @var array<int, Lock> $locks */
        $locks = [];
        $claimedPackages = $registryPackages->filter(function (RegistryPackage $package) use (&$locks, $onlyStale, $staleHours): bool {
            $lock = Cache::lock("repo-watch:registry-refresh:{$package->id}", 150);

            if (! $lock->get()) {
                return false;
            }

            $package->refresh();
            if ($onlyStale && ! $this->isStale($package, $staleHours)) {
                $lock->release();

                return false;
            }

            $locks[] = $lock;

            return true;
        });

        try {
            $this->refreshRegistryPackages($claimedPackages);
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }

        return $claimedPackages->count();
    }

    public function refreshStalePackages(?int $staleHours = null): int
    {
        /** @var Collection<int, WatchedPackage> $legacyPackages */
        $legacyPackages = WatchedPackage::query()
            ->whereNull('registry_package_id')
            ->limit(200)
            ->get();
        $this->ensureRegistryPackages($legacyPackages);

        $hours = $staleHours ?? config('services.github.repo_watch_refresh_hours', 6);

        $registryPackageIds = RegistryPackage::query()
            ->where(function ($query) use ($hours) {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<=', now()->subHours($hours));
            })
            ->orderBy('last_checked_at')
            ->limit(200)
            ->pluck('id')
            ->all();

        return $this->refreshRegistryPackageIds($registryPackageIds, onlyStale: true, staleHours: $hours);
    }

    /**
     * @param  Collection<int, RegistryPackage>  $registryPackages
     */
    private function refreshRegistryPackages(Collection $registryPackages): void
    {
        if ($registryPackages->isEmpty()) {
            return;
        }

        $registryResults = $this->registryService->resolveLatestMany(
            $registryPackages
                ->map(fn (RegistryPackage $package) => [
                    'ecosystem' => $package->ecosystem,
                    'package_name' => $package->package_name,
                    'current_version' => null,
                ])
                ->all()
        );
        $timestamp = now();
        $updates = [];

        foreach ($registryPackages as $package) {
            $registryKey = implode(':', [$package->ecosystem, $package->package_name, 'null']);
            $registry = $registryResults[$registryKey] ?? [
                'latest_version' => null,
                'registry_url' => null,
            ];
            $resolvedLatestVersion = $registry['latest_version'];
            $resolved = is_string($resolvedLatestVersion) && $resolvedLatestVersion !== '';

            $updates[] = [
                'id' => $package->id,
                'ecosystem' => $package->ecosystem,
                'package_name' => $package->package_name,
                'latest_version' => $resolved ? $resolvedLatestVersion : $package->latest_version,
                'registry_url' => $registry['registry_url'] ?? $package->registry_url,
                'last_checked_at' => $resolved ? $timestamp : $package->last_checked_at,
                'last_succeeded_at' => $resolved ? $timestamp : $package->last_succeeded_at,
                'last_error' => $resolved ? null : 'Registry did not return a latest version.',
                'created_at' => $package->created_at ?? $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        RegistryPackage::query()->upsert(
            $updates,
            ['id'],
            ['latest_version', 'registry_url', 'last_checked_at', 'last_succeeded_at', 'last_error', 'updated_at']
        );
    }

    private function registryPackageFor(WatchedPackage $watchedPackage): RegistryPackage
    {
        if ($watchedPackage->registryPackage instanceof RegistryPackage) {
            return $watchedPackage->registryPackage;
        }

        /** @var Collection<int, WatchedPackage> $packages */
        $packages = new Collection([$watchedPackage]);
        $this->ensureRegistryPackages($packages);

        $freshPackage = $watchedPackage->fresh('registryPackage');
        $registryPackage = $freshPackage?->getRelationValue('registryPackage');

        if (! $registryPackage instanceof RegistryPackage) {
            throw new RuntimeException('Unable to create shared registry package.');
        }

        return $registryPackage;
    }

    private function isStale(RegistryPackage $package, ?int $staleHours = null): bool
    {
        $hours = $staleHours ?? (int) config('services.github.repo_watch_refresh_hours', 6);
        $lastCheckedAt = $package->last_checked_at;

        return ! $lastCheckedAt instanceof CarbonInterface
            || $lastCheckedAt->lessThanOrEqualTo(now()->subHours($hours));
    }

    /**
     * Backfills rows created by an older release or old queued job.
     *
     * @param  Collection<int, WatchedPackage>  $watchedPackages
     */
    private function ensureRegistryPackages(Collection $watchedPackages): void
    {
        $missingPackages = $watchedPackages
            ->filter(fn (WatchedPackage $package) => $package->registry_package_id === null);

        if ($missingPackages->isEmpty()) {
            return;
        }

        $timestamp = now();
        $rows = $missingPackages
            ->map(fn (WatchedPackage $package) => [
                'ecosystem' => $package->ecosystem,
                'package_name' => $package->package_name,
                'latest_version' => $package->getRawOriginal('latest_version'),
                'registry_url' => $package->getRawOriginal('registry_url'),
                'last_checked_at' => $package->getRawOriginal('last_checked_at'),
                'last_succeeded_at' => $package->getRawOriginal('latest_version') !== null
                    ? $package->getRawOriginal('last_checked_at')
                    : null,
                'last_error' => $package->getRawOriginal('last_error'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->keyBy(fn (array $row) => "{$row['ecosystem']}:{$row['package_name']}")
            ->values();

        RegistryPackage::query()->insertOrIgnore($rows->all());

        $registryPackages = RegistryPackage::query()
            ->where(function ($query) use ($rows) {
                foreach ($rows->groupBy('ecosystem') as $ecosystem => $ecosystemRows) {
                    $query->orWhere(function ($query) use ($ecosystem, $ecosystemRows) {
                        $query->where('ecosystem', $ecosystem)
                            ->whereIn('package_name', $ecosystemRows->pluck('package_name'));
                    });
                }
            })
            ->get()
            ->keyBy(fn (RegistryPackage $package) => "{$package->ecosystem}:{$package->package_name}");

        foreach ($missingPackages as $watchedPackage) {
            $registryPackage = $registryPackages->get(
                "{$watchedPackage->ecosystem}:{$watchedPackage->package_name}"
            );

            if ($registryPackage instanceof RegistryPackage) {
                $watchedPackage->update(['registry_package_id' => $registryPackage->id]);
            }
        }
    }
}
