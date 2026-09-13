<?php

namespace Tests\Feature;

use App\Jobs\RefreshRegistryPackages;
use App\Jobs\ScanWatchedRepository;
use App\Models\Repo\WatchedRepository;
use App\Services\Packages\PackageWatchRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class GithubWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_requires_a_configured_secret(): void
    {
        Config::set('services.github.webhook_secret', null);

        $this->postJson('/api/github/webhooks/repo-watch', [])
            ->assertStatus(503);
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        Config::set('services.github.webhook_secret', 'test-secret');

        $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256=invalid',
        ])->postJson('/api/github/webhooks/repo-watch', [])
            ->assertUnauthorized();
    }

    public function test_push_webhook_refreshes_the_matching_repository(): void
    {
        Queue::fake();
        Config::set('services.github.webhook_secret', 'test-secret');
        $payload = [
            'repository' => [
                'owner' => ['login' => 'react-laravel'],
                'name' => 'repo-watch',
            ],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $service = Mockery::mock(PackageWatchRefreshService::class);
        $service->shouldReceive('registryPackageIdsForRepository')
            ->once()
            ->with('react-laravel', 'repo-watch')
            ->andReturn([11, 12, 13]);
        $this->app->instance(PackageWatchRefreshService::class, $service);

        WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'react-laravel',
            'repo' => 'repo-watch',
            'url' => 'https://github.com/react-laravel/repo-watch',
            'full_name' => 'react-laravel/repo-watch',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'test-secret'),
        ])->postJson('/api/github/webhooks/repo-watch', $payload)
            ->assertOk()
            ->assertJsonPath('refreshed_packages', 3)
            ->assertJsonPath('queued_repository_scans', 1);

        Queue::assertPushed(
            RefreshRegistryPackages::class,
            fn (RefreshRegistryPackages $job) => $job->registryPackageIds === [11, 12, 13]
        );
        Queue::assertPushed(ScanWatchedRepository::class);
    }
}
