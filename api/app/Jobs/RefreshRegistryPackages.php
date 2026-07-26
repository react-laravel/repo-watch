<?php

namespace App\Jobs;

use App\Services\Packages\PackageWatchRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshRegistryPackages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<int, int>  $registryPackageIds
     */
    public function __construct(public readonly array $registryPackageIds) {}

    public function handle(PackageWatchRefreshService $refreshService): void
    {
        $refreshService->refreshRegistryPackageIds($this->registryPackageIds, onlyStale: true);
    }
}
