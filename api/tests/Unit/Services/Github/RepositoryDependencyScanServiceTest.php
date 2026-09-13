<?php

namespace Tests\Unit\Services\Github;

use App\Models\Repo\WatchedRepository;
use App\Services\Github\GithubRateLimitGuard;
use App\Services\Github\RepositoryDependencyScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepositoryDependencyScanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_defers_when_rate_limit_floor_is_reached(): void
    {
        Cache::put('repo-watch:github-rate-limit', [
            'remaining' => 10,
            'reset' => now()->addMinutes(20)->timestamp,
            'limit' => 5000,
            'observed_at' => now()->timestamp,
        ], now()->addHour());

        $repository = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'full_name' => 'acme/demo',
            'scan_status' => WatchedRepository::STATUS_PENDING,
            'next_scan_at' => now()->subMinute(),
        ]);

        Http::fake();

        $result = app(RepositoryDependencyScanService::class)->scan($repository);

        $this->assertTrue($result['deferred']);
        $this->assertSame(0, $result['snapshots_created']);
        $this->assertSame(WatchedRepository::STATUS_PENDING, $repository->fresh()->scan_status);
        $this->assertNotNull($repository->fresh()->next_scan_at);
        Http::assertNothingSent();
    }

    public function test_rate_limit_guard_reads_github_headers(): void
    {
        $guard = app(GithubRateLimitGuard::class);
        $guard->rememberFromHeaders([
            'X-RateLimit-Remaining' => ['40'],
            'X-RateLimit-Reset' => [(string) (time() + 120)],
            'X-RateLimit-Limit' => ['5000'],
        ]);

        $this->assertTrue($guard->shouldThrottle());
        $this->assertGreaterThanOrEqual(60, $guard->secondsUntilReset());
    }
}
