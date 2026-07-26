<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\DestroyWatchedPackagesBatchRequest;
use App\Http\Requests\Tools\PreviewDependenciesRequest;
use App\Http\Requests\Tools\StoreWatchedPackagesRequest;
use App\Jobs\RefreshRegistryPackages;
use App\Models\Repo\RegistryPackage;
use App\Models\Repo\WatchedPackage;
use App\Services\Github\GithubDependencyScannerService;
use App\Services\Github\GithubRepositoryWatcherService;
use App\Services\Packages\PackageRegistryService;
use App\Services\Packages\PackageWatchRefreshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RepositoryWatchController extends Controller
{
    public function __construct(
        private readonly GithubRepositoryWatcherService $repositoryWatcherService,
        private readonly GithubDependencyScannerService $scannerService,
        private readonly PackageRegistryService $registryService,
        private readonly PackageWatchRefreshService $refreshService,
    ) {}

    public function preview(PreviewDependenciesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->scannerService->previewDependencies($validated['url']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), null, 422);
        } catch (Throwable $exception) {
            return $this->error('读取仓库依赖失败，请稍后重试', ['detail' => $exception->getMessage()], 500);
        }

        return $this->success($result);
    }

    public function index(Request $request): JsonResponse
    {
        $packages = WatchedPackage::query()
            ->with('registryPackage')
            ->where('user_id', $request->user()->id)
            ->select([
                'id', 'registry_package_id', 'user_id', 'source_provider', 'source_owner',
                'source_repo', 'source_url', 'ecosystem', 'package_name', 'manifest_path',
                'current_version_constraint', 'normalized_current_version', 'latest_version',
                'watch_level', 'latest_update_type', 'registry_url', 'last_checked_at',
                'last_error', 'metadata', 'updated_at',
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (WatchedPackage $package) => $this->transformPackage($package))
            ->sortBy(fn (array $package) => match ($package['latest_update_type']) {
                'major' => 1,
                'minor' => 2,
                'patch' => 3,
                default => 4,
            })
            ->values();

        return $this->success($packages);
    }

    public function store(StoreWatchedPackagesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            [$parsedOwner, $parsedRepo] = $this->repositoryWatcherService->parseGithubUrl($validated['source_url']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), null, 422);
        }

        if (
            Str::lower($validated['source_owner']) !== Str::lower($parsedOwner)
            || Str::lower($validated['source_repo']) !== Str::lower($parsedRepo)
        ) {
            return $this->error('source_url 与 source_owner/source_repo 不一致', null, 422);
        }

        $normalizedOwner = Str::lower($parsedOwner);
        $normalizedRepo = Str::lower($parsedRepo);
        $userId = (int) $request->user()->id;
        $timestamp = now();
        $selectedPackages = collect($validated['packages'])
            ->keyBy(fn (array $package) => "{$package['ecosystem']}:{$package['package_name']}")
            ->values();

        [$createdPackages, $registryPackageIds] = DB::transaction(function () use (
            $selectedPackages,
            $validated,
            $userId,
            $normalizedOwner,
            $normalizedRepo,
            $timestamp,
        ) {
            RegistryPackage::query()->insertOrIgnore(
                $selectedPackages->map(fn (array $package) => [
                    'ecosystem' => $package['ecosystem'],
                    'package_name' => $package['package_name'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all()
            );

            $registryPackages = RegistryPackage::query()
                ->where(function ($query) use ($selectedPackages) {
                    foreach ($selectedPackages->groupBy('ecosystem') as $ecosystem => $packages) {
                        $query->orWhere(function ($query) use ($ecosystem, $packages) {
                            $query->where('ecosystem', $ecosystem)
                                ->whereIn('package_name', $packages->pluck('package_name'));
                        });
                    }
                })
                ->get()
                ->keyBy(fn (RegistryPackage $package) => "{$package->ecosystem}:{$package->package_name}");

            $packageRows = $selectedPackages->map(function (array $package) use (
                $registryPackages,
                $validated,
                $userId,
                $normalizedOwner,
                $normalizedRepo,
                $timestamp,
            ) {
                /** @var RegistryPackage $registryPackage */
                $registryPackage = $registryPackages->get(
                    "{$package['ecosystem']}:{$package['package_name']}"
                );

                return [
                    'registry_package_id' => $registryPackage->id,
                    'user_id' => $userId,
                    'source_provider' => 'github',
                    'source_owner' => $normalizedOwner,
                    'source_repo' => $normalizedRepo,
                    'source_url' => $validated['source_url'],
                    'ecosystem' => $package['ecosystem'],
                    'package_name' => $package['package_name'],
                    'manifest_path' => $package['manifest_path'] ?? null,
                    'current_version_constraint' => $package['current_version_constraint'] ?? null,
                    'normalized_current_version' => $package['normalized_current_version'] ?? null,
                    'watch_level' => $package['watch_level'],
                    'metadata' => json_encode([
                        'dependency_group' => $package['dependency_group'] ?? null,
                        'current_version_source' => $package['current_version_source'] ?? null,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            });

            WatchedPackage::query()->upsert(
                $packageRows->all(),
                ['user_id', 'source_provider', 'source_owner', 'source_repo', 'ecosystem', 'package_name'],
                [
                    'registry_package_id', 'source_url', 'manifest_path',
                    'current_version_constraint', 'normalized_current_version',
                    'watch_level', 'metadata', 'updated_at',
                ]
            );

            $savedPackages = WatchedPackage::query()
                ->with('registryPackage')
                ->where('user_id', $userId)
                ->where('source_provider', 'github')
                ->where('source_owner', $normalizedOwner)
                ->where('source_repo', $normalizedRepo)
                ->get()
                ->keyBy(fn (WatchedPackage $package) => "{$package->ecosystem}:{$package->package_name}");

            return [
                $selectedPackages
                    ->map(fn (array $package) => $savedPackages->get(
                        "{$package['ecosystem']}:{$package['package_name']}"
                    ))
                    ->filter()
                    ->values(),
                $registryPackages->pluck('id')->values()->all(),
            ];
        });

        RefreshRegistryPackages::dispatch($registryPackageIds);

        return $this->success(
            $createdPackages->map(fn (WatchedPackage $package) => $this->transformPackage($package))->all(),
            '依赖关注已保存，共享最新版本正在后台刷新',
            201
        );
    }

    public function destroyBatch(DestroyWatchedPackagesBatchRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $deleted = WatchedPackage::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $validated['ids'])
            ->delete();

        return $this->success(['deleted' => $deleted], '已取消关注');
    }

    public function refresh(Request $request, WatchedPackage $watchedPackage): JsonResponse
    {
        if ((int) $watchedPackage->user_id !== (int) $request->user()->id) {
            return $this->error('无权刷新该依赖', null, 403);
        }

        $watchedPackage = $this->refreshService->refreshPackage($watchedPackage);

        return $this->success($this->transformPackage($watchedPackage), '刷新成功');
    }

    public function destroy(Request $request, WatchedPackage $watchedPackage): JsonResponse
    {
        if ((int) $watchedPackage->user_id !== (int) $request->user()->id) {
            return $this->error('无权删除该依赖', null, 403);
        }

        $watchedPackage->delete();

        return $this->success([], '已取消关注');
    }

    private function transformPackage(WatchedPackage $package): array
    {
        /** @var RegistryPackage|null $registryPackage */
        $registryPackage = $package->getRelationValue('registryPackage');
        $latestVersion = $registryPackage instanceof RegistryPackage
            ? $registryPackage->latest_version
            : $package->getRawOriginal('latest_version');
        $latestUpdateType = $registryPackage instanceof RegistryPackage
            ? $this->registryService->detectUpdateType(
                $package->normalized_current_version,
                $latestVersion
            )
            : $package->getRawOriginal('latest_update_type');
        $matchesPreference = $latestUpdateType !== null && $package->watch_level === $latestUpdateType;

        if ($package->ecosystem === 'composer' && is_string($latestVersion)) {
            if (preg_match('/(\d+\.\d+\.\d+)(?:\.\d+)?/', $latestVersion, $matches) === 1) {
                $latestVersion = $matches[1];
            }
        }

        return [
            'id' => $package->id,
            'source_provider' => $package->source_provider,
            'source_owner' => $package->source_owner,
            'source_repo' => $package->source_repo,
            'source_url' => $package->source_url,
            'ecosystem' => $package->ecosystem,
            'package_name' => $package->package_name,
            'manifest_path' => $package->manifest_path,
            'current_version_constraint' => $package->current_version_constraint,
            'normalized_current_version' => $package->normalized_current_version,
            'latest_version' => $latestVersion,
            'watch_level' => $package->watch_level,
            'latest_update_type' => $latestUpdateType,
            'matches_preference' => $matchesPreference,
            'registry_url' => $registryPackage instanceof RegistryPackage
                ? $registryPackage->registry_url
                : $package->getRawOriginal('registry_url'),
            'last_checked_at' => $registryPackage instanceof RegistryPackage
                ? $registryPackage->last_checked_at
                : $package->getRawOriginal('last_checked_at'),
            'last_error' => $registryPackage instanceof RegistryPackage
                ? $registryPackage->last_error
                : $package->getRawOriginal('last_error'),
            'metadata' => $package->metadata,
            'current_version_source' => Arr::get($package->metadata ?? [], 'current_version_source'),
        ];
    }
}
