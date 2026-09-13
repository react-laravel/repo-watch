<?php

namespace App\Jobs;

use App\Models\Repo\WatchedRepository;
use App\Services\Github\RepositoryDependencyScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ScanWatchedRepository implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly int $watchedRepositoryId,
        public readonly bool $force = false,
    ) {}

    public function handle(RepositoryDependencyScanService $scanService): void
    {
        $repository = WatchedRepository::query()->find($this->watchedRepositoryId);

        if (! $repository instanceof WatchedRepository) {
            return;
        }

        try {
            $result = $scanService->scan($repository, force: $this->force);

            if ($result['deferred']) {
                $fresh = $repository->fresh();
                $delay = 300;

                if ($fresh?->next_scan_at !== null) {
                    $delay = max(60, $fresh->next_scan_at->getTimestamp() - now()->getTimestamp());
                }

                $this->release($delay);
            }
        } catch (Throwable $exception) {
            if ($this->attempts() < $this->tries) {
                $this->release(60 * $this->attempts());

                return;
            }

            throw $exception;
        }
    }
}
