<?php

namespace Tests\Unit\Services\RepoWatch;

use App\Jobs\DeliverRepoWatchNotificationWebhook;
use App\Models\Repo\DependencyChange;
use App\Models\Repo\RepoWatchNotification;
use App\Models\Repo\WatchedRepository;
use App\Services\RepoWatch\HighSignalNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HighSignalNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_notifies_only_major_updates_and_removals(): void
    {
        Queue::fake();
        Config::set('services.repo_watch.notify_webhook_url', 'https://hooks.example.test/repo-watch');

        $repository = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'full_name' => 'acme/demo',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        $detectedAt = now();

        DependencyChange::query()->insert([
            [
                'watched_repository_id' => $repository->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'react',
                'change_type' => DependencyChange::TYPE_UPDATED,
                'previous_version' => '18.2.0',
                'new_version' => '19.0.0',
                'detected_at' => $detectedAt,
                'created_at' => $detectedAt,
                'updated_at' => $detectedAt,
            ],
            [
                'watched_repository_id' => $repository->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'lodash',
                'change_type' => DependencyChange::TYPE_UPDATED,
                'previous_version' => '4.17.20',
                'new_version' => '4.17.21',
                'detected_at' => $detectedAt,
                'created_at' => $detectedAt,
                'updated_at' => $detectedAt,
            ],
            [
                'watched_repository_id' => $repository->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'left-pad',
                'change_type' => DependencyChange::TYPE_REMOVED,
                'previous_version' => '1.0.0',
                'new_version' => null,
                'detected_at' => $detectedAt,
                'created_at' => $detectedAt,
                'updated_at' => $detectedAt,
            ],
            [
                'watched_repository_id' => $repository->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'zod',
                'change_type' => DependencyChange::TYPE_ADDED,
                'previous_version' => null,
                'new_version' => '3.0.0',
                'detected_at' => $detectedAt,
                'created_at' => $detectedAt,
                'updated_at' => $detectedAt,
            ],
        ]);

        $created = app(HighSignalNotificationService::class)->notifyForScanChanges($repository, $detectedAt);

        $this->assertCount(1, $created);
        $this->assertDatabaseCount('repo_watch_notifications', 1);

        $notification = $created[0];
        $this->assertSame(RepoWatchNotification::TYPE_DEPENDENCY_HIGH_SIGNAL, $notification->type);
        $this->assertStringContainsString('acme/demo', $notification->title);
        $this->assertCount(2, $notification->payload['signals']);
        $this->assertSame(
            ['react', 'left-pad'],
            array_column($notification->payload['signals'], 'package_name')
        );

        Queue::assertPushed(
            DeliverRepoWatchNotificationWebhook::class,
            fn (DeliverRepoWatchNotificationWebhook $job) => $job->notificationId === $notification->id
        );
    }

    public function test_scan_failure_creates_notification_and_webhook_delivers(): void
    {
        Config::set('services.repo_watch.notify_webhook_url', 'https://hooks.example.test/repo-watch');
        Http::fake([
            'https://hooks.example.test/repo-watch' => Http::response(['ok' => true], 200),
        ]);

        $repository = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'broken',
            'url' => 'https://github.com/acme/broken',
            'full_name' => 'acme/broken',
            'scan_status' => WatchedRepository::STATUS_ERROR,
        ]);

        $notification = app(HighSignalNotificationService::class)
            ->notifyScanFailure($repository, 'GitHub API 403');

        $this->assertInstanceOf(RepoWatchNotification::class, $notification);
        $this->assertSame(RepoWatchNotification::TYPE_SCAN_FAILED, $notification->type);

        (new DeliverRepoWatchNotificationWebhook($notification->id))->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.test/repo-watch'
            && $request['event'] === RepoWatchNotification::TYPE_SCAN_FAILED);

        $this->assertNotNull($notification->fresh()->webhook_delivered_at);
    }

    public function test_notifications_can_be_disabled(): void
    {
        Config::set('services.repo_watch.notify_enabled', false);

        $repository = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'full_name' => 'acme/demo',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        $this->assertSame(
            [],
            app(HighSignalNotificationService::class)->notifyForScanChanges($repository, now())
        );
        $this->assertNull(
            app(HighSignalNotificationService::class)->notifyScanFailure($repository, 'boom')
        );
        $this->assertDatabaseCount('repo_watch_notifications', 0);
    }
}
