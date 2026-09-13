<?php

namespace Tests\Feature\Commands;

use App\Models\Repo\DependencyChange;
use App\Models\Repo\DependencySnapshot;
use App\Models\Repo\WatchedRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneDependencySnapshotsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_keeps_newest_n_snapshots_per_manifest_and_ages_out_changes(): void
    {
        $repository = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'full_name' => 'acme/demo',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        $otherRepo = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'other',
            'url' => 'https://github.com/acme/other',
            'full_name' => 'acme/other',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        for ($i = 1; $i <= 12; $i++) {
            DependencySnapshot::query()->create([
                'watched_repository_id' => $repository->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'packages_hash' => "hash-{$i}",
                'packages' => [],
                'package_count' => 0,
                'scanned_at' => now()->subDays(12 - $i),
            ]);
        }

        DependencySnapshot::query()->create([
            'watched_repository_id' => $repository->id,
            'ecosystem' => 'composer',
            'manifest_path' => 'composer.json',
            'packages_hash' => 'composer-only',
            'packages' => [],
            'package_count' => 0,
            'scanned_at' => now(),
        ]);

        DependencySnapshot::query()->create([
            'watched_repository_id' => $otherRepo->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'packages_hash' => 'other-only',
            'packages' => [],
            'package_count' => 0,
            'scanned_at' => now(),
        ]);

        $oldChange = DependencyChange::query()->create([
            'watched_repository_id' => $repository->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'package_name' => 'left-pad',
            'change_type' => DependencyChange::TYPE_REMOVED,
            'detected_at' => now()->subDays(120),
        ]);

        $recentChange = DependencyChange::query()->create([
            'watched_repository_id' => $repository->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'package_name' => 'react',
            'change_type' => DependencyChange::TYPE_UPDATED,
            'detected_at' => now()->subDays(2),
        ]);

        $this->artisan('repo-watch:prune-snapshots', [
            '--keep' => 10,
            '--changes-days' => 90,
        ])->assertSuccessful()
            ->expectsOutputToContain('Deleted 2 snapshot(s)');

        $this->assertSame(
            10,
            DependencySnapshot::query()
                ->where('watched_repository_id', $repository->id)
                ->where('ecosystem', 'npm')
                ->where('manifest_path', 'package.json')
                ->count()
        );
        $this->assertDatabaseHas('dependency_snapshots', [
            'packages_hash' => 'composer-only',
        ]);
        $this->assertDatabaseHas('dependency_snapshots', [
            'packages_hash' => 'other-only',
        ]);
        $this->assertDatabaseMissing('dependency_changes', [
            'id' => $oldChange->id,
        ]);
        $this->assertDatabaseHas('dependency_changes', [
            'id' => $recentChange->id,
        ]);
    }

    public function test_dry_run_does_not_delete_rows(): void
    {
        $repository = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'full_name' => 'acme/demo',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            DependencySnapshot::query()->create([
                'watched_repository_id' => $repository->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'packages_hash' => "hash-{$i}",
                'packages' => [],
                'package_count' => 0,
                'scanned_at' => now()->subDays($i),
            ]);
        }

        $this->artisan('repo-watch:prune-snapshots', [
            '--keep' => 1,
            '--dry-run' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain('[dry-run] Would delete 2 snapshot(s)');

        $this->assertDatabaseCount('dependency_snapshots', 3);
    }
}
