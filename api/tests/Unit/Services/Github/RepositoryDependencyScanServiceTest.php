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

    public function test_scan_reuses_short_lived_preview_cache_for_same_github_repo(): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/demo' => Http::response([
                'owner' => ['login' => 'acme'],
                'name' => 'demo',
                'full_name' => 'acme/demo',
                'html_url' => 'https://github.com/acme/demo',
                'description' => null,
            ], 200, [
                'X-RateLimit-Remaining' => ['5000'],
                'X-RateLimit-Reset' => [(string) (time() + 3600)],
                'X-RateLimit-Limit' => ['5000'],
            ]),
            'https://api.github.com/repos/acme/demo/contents/package.json*' => Http::response([
                'content' => base64_encode(json_encode([
                    'name' => 'demo',
                    'dependencies' => ['lodash' => '4.17.21'],
                ], JSON_THROW_ON_ERROR)),
                'encoding' => 'base64',
            ]),
            'https://api.github.com/repos/acme/demo/contents/package-lock.json*' => Http::response([], 404),
            'https://api.github.com/repos/acme/demo/contents/composer.json*' => Http::response([], 404),
            'https://api.github.com/repos/acme/demo/contents/composer.lock*' => Http::response([], 404),
        ]);

        $first = WatchedRepository::query()->create([
            'user_id' => 1,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'full_name' => 'acme/demo',
            'scan_status' => WatchedRepository::STATUS_PENDING,
        ]);
        $second = WatchedRepository::query()->create([
            'user_id' => 2,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'full_name' => 'acme/demo',
            'scan_status' => WatchedRepository::STATUS_PENDING,
        ]);

        $service = app(RepositoryDependencyScanService::class);
        $service->scan($first, true);
        $service->scan($second, true);

        Http::assertSentCount(4);
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
