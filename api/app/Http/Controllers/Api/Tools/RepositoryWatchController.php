<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\DestroyWatchedPackagesBatchRequest;
use App\Http\Requests\Tools\PreviewDependenciesRequest;
use App\Http\Requests\Tools\StoreWatchedPackagesRequest;
use App\Models\Repo\WatchedPackage;
use App\Services\Github\GithubDependencyScannerService;
use App\Services\Github\GithubRepositoryWatcherService;
use App\Services\Packages\PackageRegistryService;
use App\Services\Packages\PackageWatchRefreshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
            ->where('user_id', $request->user()->id)
            ->select([
                'id', 'user_id', 'source_provider', 'source_owner', 'source_repo', 'source_url',
                'ecosystem', 'package_name', 'manifest_path', 'current_version_constraint',
                'normalized_current_version', 'latest_version', 'watch_level', 'latest_update_type',
                'registry_url', 'last_checked_at', 'last_error', 'metadata', 'updated_at',
            ])
            ->orderByRaw("CASE latest_update_type WHEN 'major' THEN 1 WHEN 'minor' THEN 2 WHEN 'patch' THEN 3 ELSE 4 END")
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (WatchedPackage $package) => $this->transformPackage($package));

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

        $registryResults = $this->registryService->resolveLatestMany(
            array_map(
                fn (array $packageData) => [
                    'ecosystem' => $packageData['ecosystem'],
                    'package_name' => $packageData['package_name'],
                    'current_version' => $packageData['normalized_current_version'] ?? null,
                ],
                $validated['packages']
            )
        );

        $createdPackages = [];

        foreach ($validated['packages'] as $packageData) {
            $registryKey = implode(':', [
                $packageData['ecosystem'],
                $packageData['package_name'],
                $packageData['normalized_current_version'] ?? 'null',
            ]);
            $registry = $registryResults[$registryKey] ?? [
                'latest_version' => null,
                'registry_url' => null,
                'update_type' => null,
            ];

            $watchedPackage = WatchedPackage::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'source_provider' => 'github',
                    'source_owner' => $normalizedOwner,
                    'source_repo' => $normalizedRepo,
                    'ecosystem' => $packageData['ecosystem'],
                    'package_name' => $packageData['package_name'],
                ],
                [
                    'source_url' => $validated['source_url'],
                    'manifest_path' => $packageData['manifest_path'] ?? null,
                    'current_version_constraint' => $packageData['current_version_constraint'] ?? null,
                    'normalized_current_version' => $packageData['normalized_current_version'] ?? null,
                    'latest_version' => $registry['latest_version'],
                    'watch_level' => $packageData['watch_level'],
                    'latest_update_type' => $registry['update_type'],
                    'registry_url' => $registry['registry_url'],
                    'last_checked_at' => now(),
                    'last_error' => null,
                    'metadata' => [
                        'dependency_group' => $packageData['dependency_group'] ?? null,
                        'current_version_source' => $packageData['current_version_source'] ?? null,
                    ],
                ]
            );

            $createdPackages[] = $this->transformPackage($watchedPackage);
        }

        return $this->success($createdPackages, '依赖关注已保存', 201);
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
        $matchesPreference = $package->latest_update_type !== null && $package->watch_level === $package->latest_update_type;
        $latestVersion = $package->latest_version;

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
            'latest_update_type' => $package->latest_update_type,
            'matches_preference' => $matchesPreference,
            'registry_url' => $package->registry_url,
            'last_checked_at' => $package->last_checked_at,
            'last_error' => $package->last_error,
            'metadata' => $package->metadata,
            'current_version_source' => Arr::get($package->metadata ?? [], 'current_version_source'),
        ];
    }
}
