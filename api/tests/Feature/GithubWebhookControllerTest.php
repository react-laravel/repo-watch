<?php

namespace Tests\Feature;

use App\Services\Packages\PackageWatchRefreshService;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class GithubWebhookControllerTest extends TestCase
{
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
        Config::set('services.github.webhook_secret', 'test-secret');
        $payload = [
            'repository' => [
                'owner' => ['login' => 'react-laravel'],
                'name' => 'repo-watch',
            ],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $service = Mockery::mock(PackageWatchRefreshService::class);
        $service->shouldReceive('refreshRepositoryPackages')
            ->once()
            ->with('react-laravel', 'repo-watch')
            ->andReturn(3);
        $this->app->instance(PackageWatchRefreshService::class, $service);

        $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'test-secret'),
        ])->postJson('/api/github/webhooks/repo-watch', $payload)
            ->assertOk()
            ->assertJsonPath('refreshed_packages', 3);
    }
}
