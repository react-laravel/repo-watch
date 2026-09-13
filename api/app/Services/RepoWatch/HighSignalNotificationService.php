<?php

namespace App\Services\RepoWatch;

use App\Jobs\DeliverRepoWatchNotificationWebhook;
use App\Models\Repo\DependencyChange;
use App\Models\Repo\PackageAdvisory;
use App\Models\Repo\PackageAdvisoryFinding;
use App\Models\Repo\RepoWatchNotification;
use App\Models\Repo\WatchedRepository;
use App\Services\Packages\PackageRegistryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HighSignalNotificationService
{
    public function __construct(
        private readonly PackageRegistryService $packageRegistryService,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.repo_watch.notify_enabled', true);
    }

    /**
     * Persist in-app notifications for high-signal changes from a scan window,
     * then optionally enqueue outbound webhook delivery.
     *
     * @return list<RepoWatchNotification>
     */
    public function notifyForScanChanges(WatchedRepository $repository, mixed $detectedAt): array
    {
        if (! $this->enabled() || $repository->isMuted()) {
            return [];
        }

        $changes = DependencyChange::query()
            ->where('watched_repository_id', $repository->id)
            ->where('detected_at', $detectedAt)
            ->orderBy('id')
            ->get();

        $highSignal = $this->filterHighSignalChanges($changes);

        if ($highSignal->isEmpty()) {
            return [];
        }

        $summary = $this->buildChangeSummary($repository, $highSignal);
        $notification = RepoWatchNotification::query()->create([
            'user_id' => $repository->user_id,
            'watched_repository_id' => $repository->id,
            'type' => RepoWatchNotification::TYPE_DEPENDENCY_HIGH_SIGNAL,
            'severity' => RepoWatchNotification::SEVERITY_HIGH,
            'title' => $summary['title'],
            'body' => $summary['body'],
            'payload' => [
                'repository' => [
                    'id' => $repository->id,
                    'full_name' => $repository->displayName(),
                    'url' => $repository->url,
                ],
                'signals' => $highSignal->map(fn (DependencyChange $change) => [
                    'dependency_change_id' => $change->id,
                    'ecosystem' => $change->ecosystem,
                    'manifest_path' => $change->manifest_path,
                    'package_name' => $change->package_name,
                    'change_type' => $change->change_type,
                    'update_type' => $this->updateTypeFor($change),
                    'previous_version' => $change->previous_version,
                    'new_version' => $change->new_version,
                ])->values()->all(),
            ],
        ]);

        $this->queueWebhook($notification);

        return [$notification];
    }

    public function notifyScanFailure(WatchedRepository $repository, string $errorMessage): ?RepoWatchNotification
    {
        if (! $this->enabled()
            || $repository->isMuted()
            || ! (bool) config('services.repo_watch.notify_on_scan_failure', true)) {
            return null;
        }

        $title = sprintf('扫描失败：%s', $repository->displayName());
        $body = Str::limit(trim($errorMessage) !== '' ? $errorMessage : '未知扫描错误', 500);

        $notification = RepoWatchNotification::query()->create([
            'user_id' => $repository->user_id,
            'watched_repository_id' => $repository->id,
            'type' => RepoWatchNotification::TYPE_SCAN_FAILED,
            'severity' => RepoWatchNotification::SEVERITY_HIGH,
            'title' => $title,
            'body' => $body,
            'payload' => [
                'repository' => [
                    'id' => $repository->id,
                    'full_name' => $repository->displayName(),
                    'url' => $repository->url,
                ],
                'error' => $body,
            ],
        ]);

        $this->queueWebhook($notification);

        return $notification;
    }

    /**
     * Notify when newly opened critical/high advisory findings appear.
     *
     * @param  Collection<int, PackageAdvisoryFinding>  $findings
     * @return list<RepoWatchNotification>
     */
    public function notifyForAdvisories(WatchedRepository $repository, Collection $findings): array
    {
        if (! $this->enabled()
            || $repository->isMuted()
            || ! (bool) config('services.repo_watch.notify_on_advisory', true)) {
            return [];
        }

        $highSignal = $findings
            ->filter(function (PackageAdvisoryFinding $finding): bool {
                $advisory = $finding->packageAdvisory;

                return $advisory instanceof PackageAdvisory && $advisory->isHighSignal();
            })
            ->values();

        if ($highSignal->isEmpty()) {
            return [];
        }

        $critical = $highSignal->filter(
            fn (PackageAdvisoryFinding $finding) => $finding->packageAdvisory?->severity === PackageAdvisory::SEVERITY_CRITICAL
        )->count();
        $high = $highSignal->count() - $critical;

        $parts = [];
        if ($critical > 0) {
            $parts[] = sprintf('%d 个 critical', $critical);
        }
        if ($high > 0) {
            $parts[] = sprintf('%d 个 high', $high);
        }

        $title = sprintf(
            '%s：%s 安全公告',
            $repository->displayName(),
            $parts !== [] ? implode('，', $parts) : sprintf('%d 条', $highSignal->count())
        );

        $body = $highSignal
            ->take(5)
            ->map(function (PackageAdvisoryFinding $finding): string {
                $advisory = $finding->packageAdvisory;

                return sprintf(
                    '%s@%s (%s)%s',
                    $finding->package_name,
                    $finding->installed_version,
                    $advisory !== null ? $advisory->severity : 'unknown',
                    ($advisory !== null && $advisory->advisory_id) ? ' '.$advisory->advisory_id : ''
                );
            })
            ->implode('；');

        if ($highSignal->count() > 5) {
            $body .= sprintf(' 等共 %d 项', $highSignal->count());
        }

        $notification = RepoWatchNotification::query()->create([
            'user_id' => $repository->user_id,
            'watched_repository_id' => $repository->id,
            'type' => RepoWatchNotification::TYPE_PACKAGE_ADVISORY,
            'severity' => RepoWatchNotification::SEVERITY_HIGH,
            'title' => $title,
            'body' => $body,
            'payload' => [
                'repository' => [
                    'id' => $repository->id,
                    'full_name' => $repository->displayName(),
                    'url' => $repository->url,
                ],
                'findings' => $highSignal->map(fn (PackageAdvisoryFinding $finding) => [
                    'finding_id' => $finding->id,
                    'package_advisory_id' => $finding->package_advisory_id,
                    'advisory_id' => $finding->packageAdvisory?->advisory_id,
                    'ecosystem' => $finding->ecosystem,
                    'manifest_path' => $finding->manifest_path,
                    'package_name' => $finding->package_name,
                    'installed_version' => $finding->installed_version,
                    'severity' => $finding->packageAdvisory?->severity,
                    'summary' => $finding->packageAdvisory?->summary,
                    'reference_url' => $finding->packageAdvisory?->reference_url,
                ])->values()->all(),
            ],
        ]);

        $this->queueWebhook($notification);

        return [$notification];
    }

    /**
     * @param  Collection<int, DependencyChange>  $changes
     * @return Collection<int, DependencyChange>
     */
    public function filterHighSignalChanges(Collection $changes): Collection
    {
        $notifyMajor = (bool) config('services.repo_watch.notify_on_major', true);
        $notifyRemoved = (bool) config('services.repo_watch.notify_on_removed', true);

        return $changes
            ->filter(function (DependencyChange $change) use ($notifyMajor, $notifyRemoved): bool {
                if ($change->change_type === DependencyChange::TYPE_REMOVED) {
                    return $notifyRemoved;
                }

                if ($change->change_type === DependencyChange::TYPE_UPDATED) {
                    return $notifyMajor && $this->updateTypeFor($change) === 'major';
                }

                // "added" stays out of the default high-signal set to keep 20–30-repo noise low.
                return false;
            })
            ->values();
    }

    private function updateTypeFor(DependencyChange $change): ?string
    {
        return $this->packageRegistryService->detectUpdateType(
            $change->previous_version,
            $change->new_version,
        );
    }

    /**
     * @param  Collection<int, DependencyChange>  $changes
     * @return array{title: string, body: string}
     */
    private function buildChangeSummary(WatchedRepository $repository, Collection $changes): array
    {
        $majors = $changes->filter(
            fn (DependencyChange $change) => $change->change_type === DependencyChange::TYPE_UPDATED
        )->count();
        $removed = $changes->filter(
            fn (DependencyChange $change) => $change->change_type === DependencyChange::TYPE_REMOVED
        )->count();

        $parts = [];
        if ($majors > 0) {
            $parts[] = sprintf('%d 个 major 升级', $majors);
        }
        if ($removed > 0) {
            $parts[] = sprintf('%d 个依赖移除', $removed);
        }

        $headline = $parts !== [] ? implode('，', $parts) : sprintf('%d 条高信号变更', $changes->count());
        $title = sprintf('%s：%s', $repository->displayName(), $headline);

        $preview = $changes
            ->take(5)
            ->map(function (DependencyChange $change): string {
                if ($change->change_type === DependencyChange::TYPE_REMOVED) {
                    return sprintf('%s 已移除', $change->package_name);
                }

                return sprintf(
                    '%s %s → %s',
                    $change->package_name,
                    $change->previous_version ?? '—',
                    $change->new_version ?? '—',
                );
            })
            ->implode('；');

        if ($changes->count() > 5) {
            $preview .= sprintf(' 等共 %d 项', $changes->count());
        }

        return [
            'title' => $title,
            'body' => $preview,
        ];
    }

    private function queueWebhook(RepoWatchNotification $notification): void
    {
        $url = config('services.repo_watch.notify_webhook_url');

        if (! is_string($url) || trim($url) === '') {
            return;
        }

        DeliverRepoWatchNotificationWebhook::dispatch($notification->id);
    }
}
