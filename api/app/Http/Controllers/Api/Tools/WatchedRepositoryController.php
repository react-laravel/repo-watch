<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\StoreWatchedRepositoryRequest;
use App\Jobs\ScanWatchedRepository;
use App\Models\Repo\DependencyChange;
use App\Models\Repo\WatchedRepository;
use App\Services\Github\GithubRepositoryWatcherService;
use App\Services\Github\RepositoryDependencyScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class WatchedRepositoryController extends Controller
{
    public function __construct(
        private readonly GithubRepositoryWatcherService $repositoryWatcherService,
        private readonly RepositoryDependencyScanService $scanService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $repositories = WatchedRepository::query()
            ->where('user_id', $request->user()->id)
            ->withCount('watchedPackages')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (WatchedRepository $repository) => $this->transformRepository($repository));

        return $this->success($repositories);
    }

    public function store(StoreWatchedRepositoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            [$owner, $repo] = $this->repositoryWatcherService->parseGithubUrl($validated['url']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), null, 422);
        }

        $repository = $this->scanService->ensureForUser(
            (int) $request->user()->id,
            $owner,
            $repo,
            $validated['url'],
        );

        $shouldScan = array_key_exists('scan', $validated) ? (bool) $validated['scan'] : true;

        if ($shouldScan) {
            ScanWatchedRepository::dispatch($repository->id);
        }

        return $this->success(
            $this->transformRepository($repository->fresh() ?? $repository),
            $shouldScan ? '仓库已加入关注，依赖快照扫描已排队' : '仓库已加入关注',
            201
        );
    }

    public function show(Request $request, WatchedRepository $watchedRepository): JsonResponse
    {
        if ((int) $watchedRepository->user_id !== (int) $request->user()->id) {
            return $this->error('无权查看该仓库', null, 403);
        }

        $watchedRepository->loadCount('watchedPackages');

        return $this->success($this->transformRepository($watchedRepository));
    }

    public function destroy(Request $request, WatchedRepository $watchedRepository): JsonResponse
    {
        if ((int) $watchedRepository->user_id !== (int) $request->user()->id) {
            return $this->error('无权删除该仓库', null, 403);
        }

        DB::transaction(function () use ($watchedRepository): void {
            $watchedRepository->watchedPackages()->delete();
            $watchedRepository->delete();
        });

        return $this->success([], '已取消关注该仓库及其依赖');
    }

    public function scan(Request $request, WatchedRepository $watchedRepository): JsonResponse
    {
        if ((int) $watchedRepository->user_id !== (int) $request->user()->id) {
            return $this->error('无权扫描该仓库', null, 403);
        }

        $sync = filter_var($request->query('sync', false), FILTER_VALIDATE_BOOL);

        if ($sync) {
            try {
                $result = $this->scanService->scan($watchedRepository, force: true);
            } catch (Throwable $exception) {
                return $this->error('扫描失败：'.$exception->getMessage(), null, 422);
            }

            return $this->success([
                'repository' => $this->transformRepository($result['repository']),
                'snapshots_created' => $result['snapshots_created'],
                'changes_detected' => $result['changes_detected'],
                'deferred' => $result['deferred'],
            ], $result['deferred'] ? '扫描因速率限制已延期' : '扫描完成');
        }

        $watchedRepository->update([
            'scan_status' => WatchedRepository::STATUS_PENDING,
            'next_scan_at' => now(),
        ]);

        ScanWatchedRepository::dispatch($watchedRepository->id, true);

        return $this->success(
            $this->transformRepository($watchedRepository->fresh() ?? $watchedRepository),
            '依赖快照扫描已排队'
        );
    }

    public function changes(Request $request, WatchedRepository $watchedRepository): JsonResponse
    {
        if ((int) $watchedRepository->user_id !== (int) $request->user()->id) {
            return $this->error('无权查看该仓库变更', null, 403);
        }

        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $changes = DependencyChange::query()
            ->where('watched_repository_id', $watchedRepository->id)
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (DependencyChange $change) => $this->transformChange($change, $watchedRepository));

        return $this->success($changes);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformRepository(WatchedRepository $repository): array
    {
        return [
            'id' => $repository->id,
            'provider' => $repository->provider,
            'owner' => $repository->owner,
            'repo' => $repository->repo,
            'full_name' => $repository->displayName(),
            'url' => $repository->url,
            'description' => $repository->description,
            'default_branch' => $repository->default_branch,
            'scan_status' => $repository->scan_status,
            'last_scanned_at' => $repository->last_scanned_at,
            'next_scan_at' => $repository->next_scan_at,
            'last_scan_error' => $repository->last_scan_error,
            'package_count' => $repository->package_count,
            'watched_packages_count' => $repository->watched_packages_count
                ?? $repository->watchedPackages()->count(),
            'updated_at' => $repository->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformChange(DependencyChange $change, ?WatchedRepository $repository = null): array
    {
        $repo = $repository ?? $change->watchedRepository;

        return [
            'id' => $change->id,
            'watched_repository_id' => $change->watched_repository_id,
            'repository' => $repo instanceof WatchedRepository ? [
                'id' => $repo->id,
                'full_name' => $repo->displayName(),
                'owner' => $repo->owner,
                'repo' => $repo->repo,
                'url' => $repo->url,
            ] : null,
            'ecosystem' => $change->ecosystem,
            'manifest_path' => $change->manifest_path,
            'package_name' => $change->package_name,
            'change_type' => $change->change_type,
            'previous_constraint' => $change->previous_constraint,
            'new_constraint' => $change->new_constraint,
            'previous_version' => $change->previous_version,
            'new_version' => $change->new_version,
            'detected_at' => $change->detected_at,
        ];
    }
}
