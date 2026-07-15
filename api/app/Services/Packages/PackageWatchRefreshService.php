<?php

namespace App\Services\Packages;

use App\Models\Repo\WatchedPackage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class PackageWatchRefreshService
{
    public function __construct(
        private readonly PackageRegistryService $registryService
    ) {}

    public function refreshPackage(WatchedPackage $watchedPackage): WatchedPackage
    {
        $registry = $this->registryService->resolveLatest(
            $watchedPackage->ecosystem,
            $watchedPackage->package_name,
            $watchedPackage->normalized_current_version
        );

        $watchedPackage->update([
            'latest_version' => $registry['latest_version'],
            'latest_update_type' => $registry['update_type'],
            'registry_url' => $registry['registry_url'],
            'last_checked_at' => now(),
            'last_error' => null,
        ]);

        return $watchedPackage->fresh();
    }

    public function refreshRepositoryPackages(string $owner, string $repo): int
    {
        $normalizedOwner = Str::lower($owner);
        $normalizedRepo = Str::lower($repo);

        /** @var Collection<int, WatchedPackage> $packages */
        $packages = WatchedPackage::query()
            ->where('source_provider', 'github')
            ->whereRaw('LOWER(source_owner) = ?', [$normalizedOwner])
            ->whereRaw('LOWER(source_repo) = ?', [$normalizedRepo])
            ->get();

        $this->refreshPackages($packages);

        return $packages->count();
    }

    public function refreshStalePackages(?int $staleHours = null): int
    {
        $hours = $staleHours ?? config('services.github.repo_watch_refresh_hours', 6);

        /** @var Collection<int, WatchedPackage> $packages */
        $packages = WatchedPackage::query()
            ->where(function ($query) use ($hours) {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<=', now()->subHours($hours));
            })
            ->orderBy('last_checked_at')
            ->limit(200)
            ->get();

        $this->refreshPackages($packages);

        return $packages->count();
    }

    /**
     * @param  Collection<int, WatchedPackage>  $packages
     */
    private function refreshPackages(Collection $packages): void
    {
        if ($packages->isEmpty()) {
            return;
        }

        $registryResults = $this->registryService->resolveLatestMany(
            $packages
                ->map(fn (WatchedPackage $package) => [
                    'ecosystem' => $package->ecosystem,
                    'package_name' => $package->package_name,
                    'current_version' => $package->normalized_current_version,
                ])
                ->all()
        );

        foreach ($packages as $package) {
            $registryKey = implode(':', [
                $package->ecosystem,
                $package->package_name,
                $package->normalized_current_version ?? 'null',
            ]);
            $registry = $registryResults[$registryKey] ?? [
                'latest_version' => null,
                'registry_url' => null,
                'update_type' => null,
            ];

            $package->update([
                'latest_version' => $registry['latest_version'],
                'latest_update_type' => $registry['update_type'],
                'registry_url' => $registry['registry_url'],
                'last_checked_at' => now(),
                'last_error' => null,
            ]);
        }
    }
}
