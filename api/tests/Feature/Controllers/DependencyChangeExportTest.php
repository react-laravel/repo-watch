<?php

namespace Tests\Feature\Controllers;

use App\Models\Repo\DependencyChange;
use App\Models\Repo\WatchedRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DependencyChangeExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_csv_respects_filters_window_and_ownership(): void
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
                'previous_constraint' => '^18.0.0',
                'new_constraint' => '^19.0.0',
                'detected_at' => $now->copy()->subHours(2),
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
                'detected_at' => $now->copy()->subHour(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'watched_repository_id' => $alpha->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'ancient',
                'change_type' => DependencyChange::TYPE_REMOVED,
                'previous_version' => '1.0.0',
                'new_version' => null,
                'previous_constraint' => '^1.0',
                'new_constraint' => null,
                'detected_at' => $now->copy()->subDays(5),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'watched_repository_id' => $other->id,
                'ecosystem' => 'npm',
                'manifest_path' => 'package.json',
                'package_name' => 'secret-pkg',
                'change_type' => DependencyChange::TYPE_UPDATED,
                'previous_version' => '1.0.0',
                'new_version' => '2.0.0',
                'previous_constraint' => '^1.0',
                'new_constraint' => '^2.0',
                'detected_at' => $now->copy()->subHour(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $this->getJson('/api/repo-watch/dependency-changes/export?format=csv&hours=24&ecosystem=npm')
            ->assertOk()
            ->assertJsonPath('data.format', 'csv')
            ->assertJsonPath('data.row_count', 1)
            ->assertJsonPath('data.window_source', 'hours');

        $csv = (string) $response->json('data.content');
        $this->assertStringContainsString('detected_at,full_name,ecosystem', $csv);
        $this->assertStringContainsString('acme/alpha', $csv);
        $this->assertStringContainsString('react', $csv);
        $this->assertStringNotContainsString('laravel/framework', $csv);
        $this->assertStringNotContainsString('secret-pkg', $csv);
        $this->assertStringNotContainsString('ancient', $csv);
        $this->assertStringEndsWith('.csv', (string) $response->json('data.filename'));
    }

    public function test_export_summary_groups_by_repository(): void
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

        DependencyChange::query()->create([
            'watched_repository_id' => $alpha->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'package_name' => 'lodash',
            'change_type' => DependencyChange::TYPE_UPDATED,
            'previous_version' => '4.17.20',
            'new_version' => '4.17.21',
            'detected_at' => now()->subHour(),
        ]);

        $response = $this->getJson('/api/repo-watch/dependency-changes/export?format=summary&hours=24')
            ->assertOk()
            ->assertJsonPath('data.format', 'summary')
            ->assertJsonPath('data.row_count', 1);

        $text = (string) $response->json('data.content');
        $this->assertStringContainsString('## acme/alpha (1)', $text);
        $this->assertStringContainsString('[updated] npm lodash 4.17.20 → 4.17.21', $text);
        $this->assertStringEndsWith('.txt', (string) $response->json('data.filename'));
    }

    public function test_export_rejects_invalid_format(): void
    {
        $this->withRepoWatchIdentity();

        $this->getJson('/api/repo-watch/dependency-changes/export?format=pdf')
            ->assertStatus(422);
    }
}
