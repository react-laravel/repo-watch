<?php

namespace App\Services\RepoWatch;

use App\Models\Repo\DependencyChange;
use App\Models\Repo\DependencySnapshot;
use App\Models\Repo\WatchedRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DependencySnapshotRetentionService
{
    /**
     * Keep the newest N snapshots per (repository, ecosystem, manifest_path),
     * then prune dependency_changes older than the retention window.
     *
     * @return array{snapshots_deleted: int, changes_deleted: int, groups_pruned: int}
     */
    public function prune(?int $keep = null, ?int $changeRetentionDays = null, bool $dryRun = false): array
    {
        $keep = max(1, $keep ?? (int) config('services.github.repo_watch_snapshot_keep', 10));
        $changeRetentionDays = max(
            1,
            $changeRetentionDays ?? (int) config('services.github.repo_watch_change_retention_days', 90)
        );

        $groups = DependencySnapshot::query()
            ->select(['watched_repository_id', 'ecosystem', 'manifest_path'])
            ->groupBy('watched_repository_id', 'ecosystem', 'manifest_path')
            ->get();

        $snapshotIdsToDelete = [];
        $groupsPruned = 0;

        foreach ($groups as $group) {
            $keeperIds = DependencySnapshot::query()
                ->where('watched_repository_id', $group->watched_repository_id)
                ->where('ecosystem', $group->ecosystem)
                ->where('manifest_path', $group->manifest_path)
                ->orderByDesc('scanned_at')
                ->orderByDesc('id')
                ->limit($keep)
                ->pluck('id')
                ->all();

            $excessIds = DependencySnapshot::query()
                ->where('watched_repository_id', $group->watched_repository_id)
                ->where('ecosystem', $group->ecosystem)
                ->where('manifest_path', $group->manifest_path)
                ->when(
                    $keeperIds !== [],
                    fn ($query) => $query->whereNotIn('id', $keeperIds)
                )
                ->pluck('id')
                ->all();

            if ($excessIds === []) {
                continue;
            }

            $groupsPruned++;
            array_push($snapshotIdsToDelete, ...$excessIds);
        }

        $changeCutoff = now()->subDays($changeRetentionDays);
        $oldChangeIds = DependencyChange::query()
            ->where('detected_at', '<', $changeCutoff)
            ->pluck('id')
            ->all();

        if ($dryRun) {
            return [
                'snapshots_deleted' => count($snapshotIdsToDelete),
                'changes_deleted' => count($oldChangeIds),
                'groups_pruned' => $groupsPruned,
            ];
        }

        $snapshotsDeleted = 0;
        $changesDeleted = 0;

        DB::transaction(function () use ($snapshotIdsToDelete, $oldChangeIds, &$snapshotsDeleted, &$changesDeleted): void {
            foreach (array_chunk($snapshotIdsToDelete, 500) as $chunk) {
                // nullOnDelete keeps change rows; age-based prune below bounds growth.
                $snapshotsDeleted += DependencySnapshot::query()->whereIn('id', $chunk)->delete();
            }

            foreach (array_chunk($oldChangeIds, 500) as $chunk) {
                $changesDeleted += DependencyChange::query()->whereIn('id', $chunk)->delete();
            }
        });

        return [
            'snapshots_deleted' => $snapshotsDeleted,
            'changes_deleted' => $changesDeleted,
            'groups_pruned' => $groupsPruned,
        ];
    }

    /**
     * @param  Collection<int, WatchedRepository>  $repositories
     * @return array{
     *   total: int,
     *   by_status: array{idle: int, pending: int, scanning: int, error: int},
     *   never_scanned: int,
     *   overdue: int,
     *   failing: int
     * }
     */
    public function buildHealthSummary(Collection $repositories): array
    {
        $byStatus = [
            WatchedRepository::STATUS_IDLE => 0,
            WatchedRepository::STATUS_PENDING => 0,
            WatchedRepository::STATUS_SCANNING => 0,
            WatchedRepository::STATUS_ERROR => 0,
        ];

        $neverScanned = 0;
        $overdue = 0;
        $failing = 0;

        foreach ($repositories as $repository) {
            $status = $repository->scan_status;
            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status]++;
            }

            if ($repository->last_scanned_at === null) {
                $neverScanned++;
            }

            $isDue = $repository->next_scan_at === null
                || $repository->next_scan_at->lessThanOrEqualTo(now());

            if (
                $isDue
                && in_array($status, [
                    WatchedRepository::STATUS_IDLE,
                    WatchedRepository::STATUS_PENDING,
                    WatchedRepository::STATUS_ERROR,
                ], true)
            ) {
                $overdue++;
            }

            if ($status === WatchedRepository::STATUS_ERROR) {
                $failing++;
            }
        }

        return [
            'total' => $repositories->count(),
            'by_status' => $byStatus,
            'never_scanned' => $neverScanned,
            'overdue' => $overdue,
            'failing' => $failing,
        ];
    }
}
