<?php

namespace App\Console\Commands\RepoWatch;

use App\Services\Packages\PackageWatchRefreshService;
use Illuminate\Console\Command;

class RefreshWatchedPackagesCommand extends Command
{
    protected $signature = 'repo-watch:refresh {--repo=} {--hours=}';

    protected $description = 'Refresh watched package versions from registries';

    public function __construct(
        private readonly PackageWatchRefreshService $refreshService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $repo = $this->option('repo');

        if (is_string($repo) && $repo !== '') {
            [$owner, $name] = array_pad(explode('/', $repo, 2), 2, null);

            if (! $owner || ! $name) {
                $this->error('The --repo option must use owner/repo format.');

                return self::FAILURE;
            }

            $count = $this->refreshService->refreshRepositoryPackages($owner, $name);
            $this->info("Refreshed {$count} watched packages for {$owner}/{$name}.");

            return self::SUCCESS;
        }

        $hoursOption = $this->option('hours');
        $hours = is_numeric($hoursOption) ? (int) $hoursOption : null;
        $count = $this->refreshService->refreshStalePackages($hours);

        $this->info("Refreshed {$count} stale watched packages.");

        return self::SUCCESS;
    }
}
