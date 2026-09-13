<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\BulkStoreWatchedRepositoriesRequest;
use App\Http\Requests\Tools\StoreWatchedRepositoryRequest;
use App\Jobs\ScanWatchedRepository;
use App\Models\Repo\DependencyChange;
use App\Models\Repo\WatchedRepository;
use App\Services\Github\GithubRepositoryWatcherService;
use App\Services\Github\RepositoryDependencyScanService;
use App\Services\RepoWatch\DependencySnapshotRetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WatchedRepositoryController extends Controller
{
    public function __construct(
        private readonly GithubRepositoryWatcherService $repositoryWatcherService,
        private readonly RepositoryDependencyScanService $scanService,
        private readonly DependencySnapshotRetentionService $retentionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $repositories = WatchedRepository::query()
            ->where('user_id', $request->user()->id)
            ->withCount('watchedPackages')
            ->orderByDesc('updated_at')
            ->get()
            ->sortBy(function (WatchedRepository $repository): int {
                return match ($repository->scan_status) {
                    WatchedRepository::STATUS_ERROR => 0,
                    WatchedRepository::STATUS_SCANNING => 1,
                    WatchedRepository::STATUS_PENDING => 2,
                    default => 3,
                };
            })
            ->values();

        return $this->success([
            'repositories' => $repositories
                ->map(fn (WatchedRepository $repository) => $this->transformRepository($repository))
                ->all(),
            'health' => $this->retentionService->buildHealthSummary($repositories),
            'retention' => $this->retentionPolicy(),
        ]);
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

    public function storeBulk(BulkStoreWatchedRepositoriesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $shouldScan = array_key_exists('scan', $validated) ? (bool) $validated['scan'] : true;
        $userId = (int) $request->user()->id;
        $delayMs = max(0, (int) config('services.github.repo_watch_scan_delay_ms', 750));

        $results = [];
        $created = 0;
        $alreadyWatched = 0;
        $invalid = 0;
        $scanIndex = 0;
        $seen = [];

        foreach ($validated['repositories'] as $reference) {
            try {
                [$owner, $repo, $url] = $this->repositoryWatcherService->parseRepositoryReference($reference);
            } catch (RuntimeException $exception) {
                $invalid++;
                $results[] = [
                    'input' => $reference,
                    'status' => 'invalid',
                    'message' => $exception->getMessage(),
                    'repository' => null,
                ];

                continue;
            }

            $key = Str::lower($owner).'/'.Str::lower($repo);

            if (isset($seen[$key])) {
                $alreadyWatched++;
                $results[] = [
                    'input' => $reference,
                    'status' => 'duplicate_in_request',
                    'message' => '请求中重复的仓库引用',
                    'repository' => null,
                ];

                continue;
            }
            $seen[$key] = true;

            $existing = WatchedRepository::query()
                ->where('user_id', $userId)
                ->where('provider', 'github')
                ->where('owner', Str::lower($owner))
                ->where('repo', Str::lower($repo))
                ->first();

            if ($existing instanceof WatchedRepository) {
                $alreadyWatched++;
                $results[] = [
                    'input' => $reference,
                    'status' => 'already_watched',
                    'message' => '已在关注列表中',
                    'repository' => $this->transformRepository($existing),
                ];

                continue;
            }

            $repository = $this->scanService->ensureForUser($userId, $owner, $repo, $url);
            $created++;

            if ($shouldScan) {
                ScanWatchedRepository::dispatch($repository->id)
                    ->delay(now()->addMilliseconds($delayMs * $scanIndex));
                $scanIndex++;
            }

            $results[] = [
                'input' => $reference,
                'status' => 'created',
                'message' => $shouldScan ? '已加入关注并排队扫描' : '已加入关注',
                'repository' => $this->transformRepository($repository->fresh() ?? $repository),
            ];
        }

        return $this->success([
            'summary' => [
                'created' => $created,
                'already_watched' => $alreadyWatched,
                'invalid' => $invalid,
                'total' => count($results),
                'scans_queued' => $scanIndex,
            ],
            'results' => $results,
        ], sprintf('批量导入完成：新增 %d，已存在 %d，无效 %d', $created, $alreadyWatched, $invalid), 201);
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

    public function scanUnhealthy(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $delayMs = max(0, (int) config('services.github.repo_watch_scan_delay_ms', 750));

        $repositories = WatchedRepository::query()
            ->where('user_id', $userId)
            ->where(function ($query): void {
                $query->where('scan_status', WatchedRepository::STATUS_ERROR)
                    ->orWhereNull('last_scanned_at');
            })
            ->orderBy('id')
            ->get();

        $queued = 0;
        foreach ($repositories as $index => $repository) {
            $repository->update([
                'scan_status' => WatchedRepository::STATUS_PENDING,
                'next_scan_at' => now(),
            ]);

            ScanWatchedRepository::dispatch($repository->id, true)
                ->delay(now()->addMilliseconds($delayMs * $index));
            $queued++;
        }

        $fresh = WatchedRepository::query()
            ->where('user_id', $userId)
            ->withCount('watchedPackages')
            ->get();

        return $this->success([
            'queued' => $queued,
            'health' => $this->retentionService->buildHealthSummary($fresh),
            'retention' => $this->retentionPolicy(),
        ], $queued > 0 ? sprintf('已排队重新扫描 %d 个仓库', $queued) : '没有需要重新扫描的仓库');
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

    /**
     * @return array{snapshot_keep: int, change_retention_days: int}
     */
    private function retentionPolicy(): array
    {
        return [
            'snapshot_keep' => max(1, (int) config('services.github.repo_watch_snapshot_keep', 10)),
            'change_retention_days' => max(1, (int) config('services.github.repo_watch_change_retention_days', 90)),
        ];
    }
}
