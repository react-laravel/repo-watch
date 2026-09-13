<?php

namespace App\Services\RepoWatch;

use App\Models\Repo\DependencyChange;
use App\Models\Repo\PackageAdvisoryFinding;
use App\Models\Repo\RepoWatchNotification;
use App\Models\Repo\WatchedRepository;
use Illuminate\Support\Carbon;

/**
 * Cheap fleet-wide "what mattered since T" summary for 20–30 watched repos.
 * Reads only local tables — no GitHub PAT spend.
 */
class FleetActivityDigestService
{
    /**
     * @return array{
     *   since: string,
     *   until: string,
     *   window_hours: float,
     *   source: 'since'|'hours'|'default'|'clamped',
     *   totals: array{
     *     dependency_changes: int,
     *     notifications: int,
     *     unread_notifications: int,
     *     advisories_new: int
     *   },
     *   by_repository: list<array{
     *     id: int,
     *     full_name: string,
     *     url: string,
     *     dependency_changes: int,
     *     change_types: array{added: int, updated: int, removed: int},
     *     notifications: int,
     *     advisories_new: int
     *   }>,
     *   repositories_capped: bool,
     *   policy: array{default_hours: int, max_hours: int, max_repositories: int}
     * }
     */
    public function build(int $userId, ?string $sinceInput = null, ?int $hoursInput = null): array
    {
        $defaultHours = max(1, (int) config('services.repo_watch.digest_default_hours', 24));
        $maxHours = max($defaultHours, (int) config('services.repo_watch.digest_max_hours', 48));
        $maxRepositories = max(1, (int) config('services.repo_watch.digest_max_repositories', 30));

        $until = now();
        [$since, $source] = $this->resolveWindow($until, $sinceInput, $hoursInput, $defaultHours, $maxHours);

        $repositories = WatchedRepository::query()
            ->where('user_id', $userId)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'url', 'owner', 'repo']);

        $repositoryIds = $repositories->pluck('id');

        $policy = [
            'default_hours' => $defaultHours,
            'max_hours' => $maxHours,
            'max_repositories' => $maxRepositories,
        ];

        if ($repositoryIds->isEmpty()) {
            return [
                'since' => $since->toIso8601String(),
                'until' => $until->toIso8601String(),
                'window_hours' => round($since->diffInSeconds($until) / 3600, 2),
                'source' => $source,
                'totals' => [
                    'dependency_changes' => 0,
                    'notifications' => 0,
                    'unread_notifications' => 0,
                    'advisories_new' => 0,
                ],
                'by_repository' => [],
                'repositories_capped' => false,
                'policy' => $policy,
            ];
        }

        $changeRows = DependencyChange::query()
            ->toBase()
            ->selectRaw('watched_repository_id, change_type, COUNT(*) as aggregate')
            ->whereIn('watched_repository_id', $repositoryIds->all())
            ->where('detected_at', '>=', $since)
            ->groupBy('watched_repository_id', 'change_type')
            ->get();

        $notificationRows = RepoWatchNotification::query()
            ->toBase()
            ->selectRaw('watched_repository_id, COUNT(*) as aggregate')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->groupBy('watched_repository_id')
            ->get();

        $advisoryRows = PackageAdvisoryFinding::query()
            ->toBase()
            ->selectRaw('watched_repository_id, COUNT(*) as aggregate')
            ->whereIn('watched_repository_id', $repositoryIds->all())
            ->where('first_detected_at', '>=', $since)
            ->groupBy('watched_repository_id')
            ->get();

        $unreadNotifications = (int) RepoWatchNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        /** @var array<int, array{added: int, updated: int, removed: int, total: int}> $changesByRepo */
        $changesByRepo = [];
        foreach ($changeRows as $row) {
            $repoId = (int) $row->watched_repository_id;
            $type = (string) $row->change_type;
            if (! isset($changesByRepo[$repoId])) {
                $changesByRepo[$repoId] = [
                    'added' => 0,
                    'updated' => 0,
                    'removed' => 0,
                    'total' => 0,
                ];
            }
            $count = (int) $row->aggregate;
            if (in_array($type, ['added', 'updated', 'removed'], true)) {
                $changesByRepo[$repoId][$type] = $count;
            }
            $changesByRepo[$repoId]['total'] += $count;
        }

        /** @var array<int|string, int> $notificationsByRepo */
        $notificationsByRepo = [];
        foreach ($notificationRows as $row) {
            $key = $row->watched_repository_id !== null ? (int) $row->watched_repository_id : 'none';
            $notificationsByRepo[$key] = (int) $row->aggregate;
        }

        /** @var array<int, int> $advisoriesByRepo */
        $advisoriesByRepo = [];
        foreach ($advisoryRows as $row) {
            $advisoriesByRepo[(int) $row->watched_repository_id] = (int) $row->aggregate;
        }

        $byRepository = $repositories
            ->map(function (WatchedRepository $repository) use ($changesByRepo, $notificationsByRepo, $advisoriesByRepo) {
                $changes = $changesByRepo[$repository->id] ?? [
                    'added' => 0,
                    'updated' => 0,
                    'removed' => 0,
                    'total' => 0,
                ];
                $notifications = $notificationsByRepo[$repository->id] ?? 0;
                $advisories = $advisoriesByRepo[$repository->id] ?? 0;

                return [
                    'id' => $repository->id,
                    'full_name' => $repository->displayName(),
                    'url' => $repository->url,
                    'dependency_changes' => $changes['total'],
                    'change_types' => [
                        'added' => $changes['added'],
                        'updated' => $changes['updated'],
                        'removed' => $changes['removed'],
                    ],
                    'notifications' => $notifications,
                    'advisories_new' => $advisories,
                    '_score' => $changes['total'] + $notifications + $advisories,
                ];
            })
            ->filter(fn (array $row) => $row['_score'] > 0)
            ->sortByDesc('_score')
            ->values();

        $capped = $byRepository->count() > $maxRepositories;
        $byRepository = $byRepository
            ->take($maxRepositories)
            ->map(function (array $row) {
                unset($row['_score']);

                return $row;
            })
            ->values()
            ->all();

        return [
            'since' => $since->toIso8601String(),
            'until' => $until->toIso8601String(),
            'window_hours' => round($since->diffInSeconds($until) / 3600, 2),
            'source' => $source,
            'totals' => [
                'dependency_changes' => (int) collect($changesByRepo)->sum('total'),
                'notifications' => (int) array_sum($notificationsByRepo),
                'unread_notifications' => $unreadNotifications,
                'advisories_new' => (int) array_sum($advisoriesByRepo),
            ],
            'by_repository' => $byRepository,
            'repositories_capped' => $capped,
            'policy' => $policy,
        ];
    }

    /**
     * @return array{0: Carbon, 1: 'since'|'hours'|'default'|'clamped'}
     */
    private function resolveWindow(
        Carbon $until,
        ?string $sinceInput,
        ?int $hoursInput,
        int $defaultHours,
        int $maxHours,
    ): array {
        $earliest = $until->copy()->subHours($maxHours);

        if (is_string($sinceInput) && trim($sinceInput) !== '') {
            $since = Carbon::parse($sinceInput);
            if ($since->gt($until)) {
                $since = $until->copy();
            }
            if ($since->lt($earliest)) {
                return [$earliest, 'clamped'];
            }

            return [$since, 'since'];
        }

        if ($hoursInput !== null) {
            $hours = max(1, min($maxHours, $hoursInput));

            return [$until->copy()->subHours($hours), 'hours'];
        }

        return [$until->copy()->subHours($defaultHours), 'default'];
    }
}
