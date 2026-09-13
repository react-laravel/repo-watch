<?php

namespace App\Console\Commands\RepoWatch;

use App\Services\RepoWatch\DependencySnapshotRetentionService;
use Illuminate\Console\Command;

class PruneDependencySnapshotsCommand extends Command
{
    protected $signature = 'repo-watch:prune-snapshots
        {--keep= : Newest snapshots to retain per manifest}
        {--changes-days= : Delete dependency_changes older than this many days}
        {--dry-run : Report deletions without writing}';

    protected $description = 'Prune old dependency snapshots (keep-N per manifest) and aged dependency changes';

    public function __construct(
        private readonly DependencySnapshotRetentionService $retentionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $keepOption = $this->option('keep');
        $daysOption = $this->option('changes-days');
        $dryRun = (bool) $this->option('dry-run');

        $keep = is_numeric($keepOption) ? (int) $keepOption : null;
        $changesDays = is_numeric($daysOption) ? (int) $daysOption : null;

        $result = $this->retentionService->prune($keep, $changesDays, $dryRun);

        $prefix = $dryRun ? '[dry-run] Would delete' : 'Deleted';
        $this->info(sprintf(
            '%s %d snapshot(s) across %d manifest group(s) and %d aged change(s).',
            $prefix,
            $result['snapshots_deleted'],
            $result['groups_pruned'],
            $result['changes_deleted'],
        ));

        return self::SUCCESS;
    }
}
