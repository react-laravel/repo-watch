<?php

namespace App\Console\Commands\RepoWatch;

use App\Jobs\ScanWatchedRepository;
use App\Models\Repo\WatchedRepository;
use App\Services\Github\GithubRateLimitGuard;
use Illuminate\Console\Command;

class ScanWatchedRepositoriesCommand extends Command
{
    protected $signature = 'repo-watch:scan-repositories {--limit=} {--force : Ignore next_scan_at and scan immediately}';

    protected $description = 'Enqueue rate-limit-aware dependency snapshot scans for watched repositories';

    public function __construct(
        private readonly GithubRateLimitGuard $rateLimitGuard,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->rateLimitGuard->shouldThrottle()) {
            $this->rateLimitGuard->logThrottleDecision('repo-watch:scan-repositories');
            $this->warn('GitHub rate limit floor reached; skipping repository scan enqueue.');

            return self::SUCCESS;
        }

        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption)
            ? max(1, (int) $limitOption)
            : max(1, (int) config('services.github.repo_watch_scan_batch_size', 5));
        $force = (bool) $this->option('force');
        $delayMs = max(0, (int) config('services.github.repo_watch_scan_delay_ms', 750));

        $staleMinutes = max(5, (int) config('services.github.repo_watch_scanning_stale_minutes', 20));
        $recovered = WatchedRepository::query()
            ->where('scan_status', WatchedRepository::STATUS_SCANNING)
            ->where('updated_at', '<=', now()->subMinutes($staleMinutes))
            ->update([
                'scan_status' => WatchedRepository::STATUS_PENDING,
                'next_scan_at' => now(),
                'last_scan_error' => 'Recovered stale scanning state after worker timeout or crash',
            ]);

        if ($recovered > 0) {
            $this->warn("Recovered {$recovered} repository(ies) stuck in scanning status.");
        }

        $query = WatchedRepository::query()
            ->whereIn('scan_status', [
                WatchedRepository::STATUS_IDLE,
                WatchedRepository::STATUS_PENDING,
                WatchedRepository::STATUS_ERROR,
            ])
            ->orderBy('next_scan_at')
            ->orderBy('id');

        if (! $force) {
            $query->where(function ($query): void {
                $query->whereNull('next_scan_at')
                    ->orWhere('next_scan_at', '<=', now());
            });
        }

        $repositories = $query->limit($limit)->get();

        if ($repositories->isEmpty()) {
            $this->info('No watched repositories need scanning.');

            return self::SUCCESS;
        }

        foreach ($repositories->values() as $index => $repository) {
            $repository->update([
                'scan_status' => WatchedRepository::STATUS_PENDING,
            ]);

            ScanWatchedRepository::dispatch($repository->id, $force)
                ->delay(now()->addMilliseconds($delayMs * $index));
        }

        $this->info("Enqueued {$repositories->count()} repository scan job(s) with {$delayMs}ms spacing.");

        return self::SUCCESS;
    }
}
