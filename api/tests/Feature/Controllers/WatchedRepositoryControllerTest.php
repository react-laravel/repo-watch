<?php

namespace Tests\Feature\Controllers;

use App\Jobs\ScanWatchedRepository;
use App\Models\Repo\DependencyChange;
use App\Models\Repo\DependencySnapshot;
use App\Models\Repo\WatchedRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WatchedRepositoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_list_and_delete_watched_repositories(): void
    {
        $this->withRepoWatchIdentity();
        Queue::fake();

        $create = $this->postJson('/api/repo-watch/repositories', [
            'url' => 'https://github.com/acme/demo',
        ])->assertCreated()
            ->assertJsonPath('data.full_name', 'acme/demo')
            ->assertJsonPath('data.scan_status', 'pending');

        Queue::assertPushed(
            ScanWatchedRepository::class,
            fn (ScanWatchedRepository $job) => $job->watchedRepositoryId === $create->json('data.id')
        );

        $this->getJson('/api/repo-watch/repositories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.owner', 'acme');

        $id = $create->json('data.id');

        $this->deleteJson("/api/repo-watch/repositories/{$id}")
            ->assertOk()
            ->assertJsonPath('message', '已取消关注该仓库及其依赖');

        $this->assertDatabaseCount('watched_repositories', 0);
    }

    public function test_saving_packages_also_creates_watched_repository(): void
    {
        $this->withRepoWatchIdentity();
        Queue::fake();

        $this->postJson('/api/repo-watch/packages', [
            'source_url' => 'https://github.com/acme/demo',
            'source_owner' => 'acme',
            'source_repo' => 'demo',
            'packages' => [[
                'ecosystem' => 'npm',
                'package_name' => 'react',
                'manifest_path' => 'package.json',
                'current_version_constraint' => '^18.2.0',
                'normalized_current_version' => '18.2.0',
                'current_version_source' => 'lock',
                'watch_level' => 'major',
                'dependency_group' => 'dependencies',
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('watched_repositories', [
            'user_id' => 42,
            'owner' => 'acme',
            'repo' => 'demo',
        ]);

        $this->assertDatabaseHas('watched_packages', [
            'package_name' => 'react',
            'watched_repository_id' => WatchedRepository::query()->value('id'),
        ]);

        Queue::assertPushed(ScanWatchedRepository::class);
    }

    public function test_user_can_bulk_import_owner_repo_list_and_urls(): void
    {
        $this->withRepoWatchIdentity();
        Queue::fake();

        WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'existing',
            'url' => 'https://github.com/acme/existing',
            'full_name' => 'acme/existing',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        $response = $this->postJson('/api/repo-watch/repositories/bulk', [
            'repositories' => [
                'acme/new-one',
                'https://github.com/acme/new-two',
                'acme/existing',
                'acme/new-one',
                'not-a-repo',
                'github.com/acme/new-three',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.summary.created', 3)
            ->assertJsonPath('data.summary.already_watched', 2)
            ->assertJsonPath('data.summary.invalid', 1)
            ->assertJsonPath('data.summary.scans_queued', 3);

        $this->assertDatabaseHas('watched_repositories', [
            'user_id' => 42,
            'owner' => 'acme',
            'repo' => 'new-one',
        ]);
        $this->assertDatabaseHas('watched_repositories', [
            'owner' => 'acme',
            'repo' => 'new-three',
        ]);
        $this->assertDatabaseCount('watched_repositories', 4);

        Queue::assertPushed(ScanWatchedRepository::class, 3);
    }

    public function test_sync_scan_creates_snapshots_and_detects_dependency_changes(): void
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
            'next_scan_at' => now()->subHour(),
        ]);

        DependencySnapshot::query()->create([
            'watched_repository_id' => $repository->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'packages_hash' => 'old-hash',
            'packages' => [[
                'package_name' => 'react',
                'current_version_constraint' => '^18.2.0',
                'normalized_current_version' => '18.2.0',
                'current_version_source' => 'lock',
                'dependency_group' => 'dependencies',
            ], [
                'package_name' => 'left-pad',
                'current_version_constraint' => '^1.0.0',
                'normalized_current_version' => '1.0.0',
                'current_version_source' => 'manifest',
                'dependency_group' => 'dependencies',
            ]],
            'package_count' => 2,
            'scanned_at' => now()->subDay(),
        ]);

        Http::fake([
            'https://api.github.com/repos/acme/demo' => Http::response([
                'full_name' => 'acme/demo',
                'name' => 'demo',
                'owner' => ['login' => 'acme'],
                'html_url' => 'https://github.com/acme/demo',
                'description' => 'Demo repository',
            ], 200, [
                'X-RateLimit-Remaining' => '4990',
                'X-RateLimit-Reset' => (string) (time() + 3600),
                'X-RateLimit-Limit' => '5000',
            ]),
            'https://api.github.com/repos/acme/demo/contents/package.json' => Http::response([
                'content' => base64_encode(json_encode([
                    'name' => 'demo',
                    'dependencies' => [
                        'react' => '^19.0.0',
                        'lodash' => '^4.17.21',
                    ],
                ])),
            ], 200),
            'https://api.github.com/repos/acme/demo/contents/package-lock.json' => Http::response([
                'content' => base64_encode(json_encode([
                    'packages' => [
                        'node_modules/react' => ['version' => '19.0.0'],
                        'node_modules/lodash' => ['version' => '4.17.21'],
                    ],
                ])),
            ], 200),
            'https://api.github.com/repos/acme/demo/contents/composer.json' => Http::response([], 404),
        ]);

        $this->postJson("/api/repo-watch/repositories/{$repository->id}/scan?sync=1")
            ->assertOk()
            ->assertJsonPath('data.snapshots_created', 1)
            ->assertJsonPath('data.changes_detected', 3)
            ->assertJsonPath('data.repository.scan_status', 'idle');

        $this->assertDatabaseHas('dependency_changes', [
            'watched_repository_id' => $repository->id,
            'package_name' => 'react',
            'change_type' => DependencyChange::TYPE_UPDATED,
            'previous_version' => '18.2.0',
            'new_version' => '19.0.0',
        ]);
        $this->assertDatabaseHas('dependency_changes', [
            'package_name' => 'lodash',
            'change_type' => DependencyChange::TYPE_ADDED,
        ]);
        $this->assertDatabaseHas('dependency_changes', [
            'package_name' => 'left-pad',
            'change_type' => DependencyChange::TYPE_REMOVED,
        ]);

        $this->getJson('/api/repo-watch/dependency-changes')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.repository.full_name', 'acme/demo');
    }
}
