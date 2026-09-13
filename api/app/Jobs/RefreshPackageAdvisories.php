<?php

namespace App\Jobs;

use App\Models\Repo\WatchedRepository;
use App\Services\Packages\PackageAdvisoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshPackageAdvisories implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly ?int $watchedRepositoryId = null,
    ) {}

    public function handle(PackageAdvisoryService $advisoryService): void
    {
        if (! $advisoryService->enabled()) {
            return;
        }

        $repository = null;
        if ($this->watchedRepositoryId !== null) {
            $repository = WatchedRepository::query()->find($this->watchedRepositoryId);
            if (! $repository instanceof WatchedRepository) {
                return;
            }
        }

        $advisoryService->refresh($repository);
    }
}
