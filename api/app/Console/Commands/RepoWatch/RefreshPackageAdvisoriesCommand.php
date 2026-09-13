<?php

namespace App\Console\Commands\RepoWatch;

use App\Jobs\RefreshPackageAdvisories;
use App\Models\Repo\WatchedRepository;
use App\Services\Packages\PackageAdvisoryService;
use Illuminate\Console\Command;

class RefreshPackageAdvisoriesCommand extends Command
{
    protected $signature = 'repo-watch:refresh-advisories
        {--repository= : Limit to a watched repository id}
        {--sync : Run inline instead of queueing}
        {--dry-run : Show inventory counts without calling OSV}';

    protected $description = 'Refresh package advisories from OSV for latest dependency snapshots';

    public function __construct(
        private readonly PackageAdvisoryService $advisoryService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->advisoryService->enabled()) {
            $this->warn('Package advisories are disabled (REPO_WATCH_ADVISORY_ENABLED=false).');

            return self::SUCCESS;
        }

        $repositoryId = $this->option('repository');
        $repository = null;

        if (is_numeric($repositoryId)) {
            $repository = WatchedRepository::query()->find((int) $repositoryId);
            if (! $repository instanceof WatchedRepository) {
                $this->error('Watched repository not found.');

                return self::FAILURE;
            }
        }

        if ((bool) $this->option('dry-run')) {
            $repos = $repository instanceof WatchedRepository
                ? collect([$repository])
                : WatchedRepository::query()->orderBy('id')->get();
            $inventory = $this->advisoryService->collectLatestPackageInventory($repos);
            $this->info(sprintf(
                '[dry-run] %d repositories, %d lock-sourced package versions would be queried.',
                $repos->count(),
                $inventory
                    ->unique(fn (array $row) => strtolower(
                        $row['ecosystem'].'|'.$row['package_name'].'|'.$row['installed_version']
                    ))
                    ->count()
            ));

            return self::SUCCESS;
        }

        if ((bool) $this->option('sync')) {
            $result = $this->advisoryService->refresh($repository);
            $this->info(sprintf(
                'Advisories refreshed: repos=%d packages=%d upserted=%d open=%d resolved=%d notifications=%d',
                $result['repositories'],
                $result['packages_queried'],
                $result['advisories_upserted'],
                $result['findings_open'],
                $result['findings_resolved'],
                $result['notifications'],
            ));

            return self::SUCCESS;
        }

        RefreshPackageAdvisories::dispatch($repository?->id);
        $this->info(
            $repository instanceof WatchedRepository
                ? sprintf('Queued advisory refresh for repository #%d.', $repository->id)
                : 'Queued advisory refresh for all watched repositories.'
        );

        return self::SUCCESS;
    }
}
