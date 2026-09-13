<?php

namespace Tests\Feature\Controllers;

use App\Models\Repo\RepoWatchNotification;
use App\Models\Repo\WatchedRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepoWatchNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_and_mark_notifications_read(): void
    {
        $this->withRepoWatchIdentity();

        $repository = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'full_name' => 'acme/demo',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        $mine = RepoWatchNotification::query()->create([
            'user_id' => 42,
            'watched_repository_id' => $repository->id,
            'type' => RepoWatchNotification::TYPE_DEPENDENCY_HIGH_SIGNAL,
            'severity' => RepoWatchNotification::SEVERITY_HIGH,
            'title' => 'acme/demo：1 个 major 升级',
            'body' => 'react 18.2.0 → 19.0.0',
            'payload' => ['signals' => []],
        ]);

        RepoWatchNotification::query()->create([
            'user_id' => 99,
            'watched_repository_id' => null,
            'type' => RepoWatchNotification::TYPE_SCAN_FAILED,
            'severity' => RepoWatchNotification::SEVERITY_HIGH,
            'title' => 'other user',
            'body' => 'secret',
            'payload' => [],
        ]);

        $this->getJson('/api/repo-watch/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.notifications.0.id', $mine->id)
            ->assertJsonPath('data.policy.on_major', true);

        $this->postJson("/api/repo-watch/notifications/{$mine->id}/read")
            ->assertOk()
            ->assertJsonPath('data.read_at', fn ($value) => $value !== null);

        $this->assertNotNull($mine->fresh()->read_at);

        $this->getJson('/api/repo-watch/notifications?unread_only=1')
            ->assertOk()
            ->assertJsonCount(0, 'data.notifications')
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_mark_all_read_only_affects_current_user(): void
    {
        $this->withRepoWatchIdentity();

        RepoWatchNotification::query()->create([
            'user_id' => 42,
            'type' => RepoWatchNotification::TYPE_SCAN_FAILED,
            'severity' => RepoWatchNotification::SEVERITY_HIGH,
            'title' => 'mine',
            'body' => 'error',
            'payload' => [],
        ]);

        $other = RepoWatchNotification::query()->create([
            'user_id' => 99,
            'type' => RepoWatchNotification::TYPE_SCAN_FAILED,
            'severity' => RepoWatchNotification::SEVERITY_HIGH,
            'title' => 'theirs',
            'body' => 'error',
            'payload' => [],
        ]);

        $this->postJson('/api/repo-watch/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 1);

        $this->assertNotNull(
            RepoWatchNotification::query()->where('user_id', 42)->value('read_at')
        );
        $this->assertNull($other->fresh()->read_at);
    }
}
