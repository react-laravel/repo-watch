<?php

namespace App\Jobs;

use App\Services\Packages\PackageWatchRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshWatchedPackages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<int, int>  $packageIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $packageIds,
    ) {}

    public function handle(PackageWatchRefreshService $refreshService): void
    {
        $refreshService->refreshPackageIds($this->userId, $this->packageIds);
    }
}
