<?php

namespace Tests\Feature\Controllers;

use App\Models\Repo\DependencyChange;
use App\Models\Repo\WatchedRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DependencyChangeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_filter_dependency_changes_by_repo_ecosystem_and_type(): void
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
        $otherUserRepo = WatchedRepository::query()->create([
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
                'previous_constraint' => '^18.0.0',
                'new_constraint' => '^19.0.0',
                'detected_at' => $now->copy()->subMinutes(3),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'watched_repository_id' => $alpha->id,
                'ecosystem' => 'composer',
                'manifest_path' => 'composer.json',
                'package_name' => 'laravel/framework',
                'change_type' => DependencyChange::TYPE_ADDED,
                'previous_version' => null,
                'new_version' => '12.0.0',
                'previous_constraint' => null,
                'new_constraint' => '^12.0',
                'detected_at' => $now->copy()->subMinutes(2),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'watched_repository_id' => $beta->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'lodash',
                'change_type' => DependencyChange::TYPE_REMOVED,
                'previous_version' => '4.17.21',
                'new_version' => null,
                'previous_constraint' => '^4.17.0',
                'new_constraint' => null,
                'detected_at' => $now->copy()->subMinute(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'watched_repository_id' => $otherUserRepo->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'secret-pkg',
                'change_type' => DependencyChange::TYPE_ADDED,
                'previous_version' => null,
                'new_version' => '1.0.0',
                'previous_constraint' => null,
                'new_constraint' => '^1.0.0',
                'detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->getJson('/api/repo-watch/dependency-changes')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/repo-watch/dependency-changes?repository_id='.$alpha->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.repository.full_name', 'acme/alpha');

        $this->getJson('/api/repo-watch/dependency-changes?ecosystem=npm')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.package_name', 'lodash')
            ->assertJsonPath('data.1.package_name', 'react');

        $this->getJson('/api/repo-watch/dependency-changes?change_type=added')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.package_name', 'laravel/framework');

        $this->getJson('/api/repo-watch/dependency-changes?repository_id='.$alpha->id.'&ecosystem=composer&change_type=added')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.package_name', 'laravel/framework');

        $this->getJson('/api/repo-watch/dependency-changes?repository_id='.$otherUserRepo->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/repo-watch/dependency-changes?ecosystem=pypi')
            ->assertStatus(422);
    }
}
