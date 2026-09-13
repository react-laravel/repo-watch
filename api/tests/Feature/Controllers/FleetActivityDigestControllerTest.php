<?php

namespace Tests\Feature\Controllers;

use App\Models\Repo\DependencyChange;
use App\Models\Repo\PackageAdvisory;
use App\Models\Repo\PackageAdvisoryFinding;
use App\Models\Repo\RepoWatchNotification;
use App\Models\Repo\WatchedRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetActivityDigestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_summarizes_changes_notifications_and_advisories_since_cursor(): void
    {
        $this->withRepoWatchIdentity();

        $alpha = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'alpha',
            'url' => 'https://github.com/acme/alpha',
            'full_name' => 'acme/alpha',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);
        $beta = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'beta',
            'url' => 'https://github.com/acme/beta',
            'full_name' => 'acme/beta',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);
        $other = WatchedRepository::query()->create([
            'user_id' => 99,
            'provider' => 'github',
            'owner' => 'other',
            'repo' => 'secret',
            'url' => 'https://github.com/other/secret',
            'full_name' => 'other/secret',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        $now = now();

        DependencyChange::query()->insert([
            [
                'watched_repository_id' => $alpha->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'react',
                'change_type' => DependencyChange::TYPE_UPDATED,
                'previous_version' => '18.0.0',
                'new_version' => '19.0.0',
                'detected_at' => $now->copy()->subHours(2),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'watched_repository_id' => $alpha->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'left-pad',
                'change_type' => DependencyChange::TYPE_REMOVED,
                'previous_version' => '1.0.0',
                'new_version' => null,
                'detected_at' => $now->copy()->subHour(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'watched_repository_id' => $beta->id,
                'ecosystem' => 'composer',
                'manifest_path' => 'composer.json',
                'package_name' => 'laravel/framework',
                'change_type' => DependencyChange::TYPE_ADDED,
                'previous_version' => null,
                'new_version' => '12.0.0',
                'detected_at' => $now->copy()->subMinutes(30),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'watched_repository_id' => $alpha->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'old-pkg',
                'change_type' => DependencyChange::TYPE_ADDED,
                'previous_version' => null,
                'new_version' => '1.0.0',
                'detected_at' => $now->copy()->subDays(3),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'watched_repository_id' => $other->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'secret',
                'change_type' => DependencyChange::TYPE_UPDATED,
                'previous_version' => '1.0.0',
                'new_version' => '2.0.0',
                'detected_at' => $now->copy()->subHour(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $recentNotification = RepoWatchNotification::query()->create([
            'user_id' => 42,
            'watched_repository_id' => $alpha->id,
            'type' => RepoWatchNotification::TYPE_DEPENDENCY_HIGH_SIGNAL,
            'severity' => RepoWatchNotification::SEVERITY_HIGH,
            'title' => 'alpha major',
            'body' => 'react bump',
            'payload' => [],
        ]);
        $recentNotification->forceFill(['created_at' => $now->copy()->subHour()])->save();

        $oldNotification = RepoWatchNotification::query()->create([
            'user_id' => 42,
            'watched_repository_id' => $beta->id,
            'type' => RepoWatchNotification::TYPE_SCAN_FAILED,
            'severity' => RepoWatchNotification::SEVERITY_HIGH,
            'title' => 'beta fail',
            'body' => 'error',
            'payload' => [],
            'read_at' => $now,
        ]);
        $oldNotification->forceFill(['created_at' => $now->copy()->subDays(5)])->save();

        RepoWatchNotification::query()->create([
            'user_id' => 99,
            'watched_repository_id' => $other->id,
            'type' => RepoWatchNotification::TYPE_SCAN_FAILED,
            'severity' => RepoWatchNotification::SEVERITY_HIGH,
            'title' => 'other',
            'body' => 'secret',
            'payload' => [],
        ]);

        $advisory = PackageAdvisory::query()->create([
            'source' => PackageAdvisory::SOURCE_OSV,
            'advisory_id' => 'GHSA-digest-0001',
            'ecosystem' => 'npm',
            'package_name' => 'lodash',
            'severity' => PackageAdvisory::SEVERITY_HIGH,
            'summary' => 'digest advisory',
            'aliases' => [],
            'last_fetched_at' => $now,
        ]);

        PackageAdvisoryFinding::query()->create([
            'watched_repository_id' => $alpha->id,
            'package_advisory_id' => $advisory->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'package_name' => 'lodash',
            'installed_version' => '4.17.20',
            'status' => PackageAdvisoryFinding::STATUS_OPEN,
            'first_detected_at' => $now->copy()->subHours(3),
            'last_seen_at' => $now,
        ]);
        PackageAdvisoryFinding::query()->create([
            'watched_repository_id' => $beta->id,
            'package_advisory_id' => $advisory->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'package_name' => 'lodash',
            'installed_version' => '4.17.20',
            'status' => PackageAdvisoryFinding::STATUS_OPEN,
            'first_detected_at' => $now->copy()->subDays(4),
            'last_seen_at' => $now,
        ]);

        $since = $now->copy()->subHours(6)->toIso8601String();

        $this->getJson('/api/repo-watch/activity-digest?since='.urlencode($since))
            ->assertOk()
            ->assertJsonPath('data.source', 'since')
            ->assertJsonPath('data.totals.dependency_changes', 3)
            ->assertJsonPath('data.totals.notifications', 1)
            ->assertJsonPath('data.totals.unread_notifications', 1)
            ->assertJsonPath('data.totals.advisories_new', 1)
            ->assertJsonPath('data.by_repository.0.full_name', 'acme/alpha')
            ->assertJsonPath('data.by_repository.0.dependency_changes', 2)
            ->assertJsonPath('data.by_repository.0.change_types.updated', 1)
            ->assertJsonPath('data.by_repository.0.change_types.removed', 1)
            ->assertJsonPath('data.by_repository.0.notifications', 1)
            ->assertJsonPath('data.by_repository.0.advisories_new', 1)
            ->assertJsonPath('data.by_repository.1.full_name', 'acme/beta')
            ->assertJsonPath('data.by_repository.1.dependency_changes', 1)
            ->assertJsonPath('data.by_repository.0.muted', false)
            ->assertJsonPath('data.by_repository.0.watch_priority', 'normal')
            ->assertJsonMissingPath('data.by_repository.2');
    }

    public function test_digest_prioritizes_high_priority_repos_and_includes_muted_flag(): void
    {
        $this->withRepoWatchIdentity();

        $high = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'high',
            'url' => 'https://github.com/acme/high',
            'full_name' => 'acme/high',
            'scan_status' => WatchedRepository::STATUS_IDLE,
            'watch_priority' => WatchedRepository::PRIORITY_HIGH,
        ]);
        $muted = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'muted',
            'url' => 'https://github.com/acme/muted',
            'full_name' => 'acme/muted',
            'scan_status' => WatchedRepository::STATUS_IDLE,
            'muted_at' => now(),
            'watch_priority' => WatchedRepository::PRIORITY_NORMAL,
        ]);

        $now = now();

        foreach ([$high, $muted] as $repository) {
            DependencyChange::query()->create([
                'watched_repository_id' => $repository->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'pkg-'.$repository->repo,
                'change_type' => DependencyChange::TYPE_ADDED,
                'new_version' => '1.0.0',
                'detected_at' => $now->copy()->subHour(),
            ]);
        }

        $this->getJson('/api/repo-watch/activity-digest?hours=24')
            ->assertOk()
            ->assertJsonPath('data.fleet.watched', 2)
            ->assertJsonPath('data.fleet.active', 1)
            ->assertJsonPath('data.fleet.muted', 1)
            ->assertJsonPath('data.totals.dependency_changes', 2)
            ->assertJsonPath('data.totals.active.dependency_changes', 1)
            ->assertJsonPath('data.totals.muted.dependency_changes', 1)
            ->assertJsonPath('data.totals.active.notifications', 0)
            ->assertJsonPath('data.totals.muted.notifications', 0)
            ->assertJsonPath('data.by_repository.0.full_name', 'acme/high')
            ->assertJsonPath('data.by_repository.0.watch_priority', 'high')
            ->assertJsonPath('data.by_repository.0.muted', false)
            ->assertJsonPath('data.by_repository.1.full_name', 'acme/muted')
            ->assertJsonPath('data.by_repository.1.muted', true);
    }

    public function test_digest_clamps_since_to_max_hours(): void
    {
        $this->withRepoWatchIdentity();

        config([
            'services.repo_watch.digest_default_hours' => 24,
            'services.repo_watch.digest_max_hours' => 48,
        ]);

        $repository = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'full_name' => 'acme/demo',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        DependencyChange::query()->create([
            'watched_repository_id' => $repository->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'package_name' => 'recent',
            'change_type' => DependencyChange::TYPE_ADDED,
            'new_version' => '1.0.0',
            'detected_at' => now()->subHours(10),
        ]);
        DependencyChange::query()->create([
            'watched_repository_id' => $repository->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'package_name' => 'ancient',
            'change_type' => DependencyChange::TYPE_ADDED,
            'new_version' => '0.1.0',
            'detected_at' => now()->subDays(10),
        ]);

        $this->getJson('/api/repo-watch/activity-digest?since='.urlencode(now()->subDays(30)->toIso8601String()))
            ->assertOk()
            ->assertJsonPath('data.source', 'clamped')
            ->assertJsonPath('data.totals.dependency_changes', 1)
            ->assertJsonPath('data.policy.max_hours', 48);
    }

    public function test_digest_defaults_to_24h_window(): void
    {
        $this->withRepoWatchIdentity();

        $this->getJson('/api/repo-watch/activity-digest')
            ->assertOk()
            ->assertJsonPath('data.source', 'default')
            ->assertJsonPath('data.policy.default_hours', 24)
            ->assertJsonPath('data.fleet.watched', 0)
            ->assertJsonPath('data.fleet.active', 0)
            ->assertJsonPath('data.fleet.muted', 0)
            ->assertJsonPath('data.totals.dependency_changes', 0)
            ->assertJsonPath('data.totals.active.dependency_changes', 0)
            ->assertJsonPath('data.totals.muted.dependency_changes', 0);
    }
}
