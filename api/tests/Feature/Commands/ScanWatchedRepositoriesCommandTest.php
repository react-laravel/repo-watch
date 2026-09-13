<?php

namespace Tests\Feature\Commands;

use App\Jobs\ScanWatchedRepository;
use App\Models\Repo\WatchedRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScanWatchedRepositoriesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_recovers_stale_scanning_repositories_before_enqueue(): void
    {
        Queue::fake();

        $stale = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'stale',
            'url' => 'https://github.com/acme/stale',
            'full_name' => 'acme/stale',
            'scan_status' => WatchedRepository::STATUS_SCANNING,
            'next_scan_at' => now()->subHour(),
            'updated_at' => now()->subMinutes(30),
        ]);
        $stale->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

        $this->artisan('repo-watch:scan-repositories', ['--limit' => 5])
            ->assertSuccessful();

        $this->assertSame(WatchedRepository::STATUS_PENDING, $stale->fresh()->scan_status);
        Queue::assertPushed(ScanWatchedRepository::class, 1);
    }

    public function test_command_groups_identical_github_identities_when_enqueueing(): void
    {
        Queue::fake();
        Config::set('services.github.repo_watch_scan_delay_ms', 100);

        WatchedRepository::query()->create([
            'user_id' => 1,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'shared',
            'url' => 'https://github.com/acme/shared',
            'full_name' => 'acme/shared',
            'scan_status' => WatchedRepository::STATUS_IDLE,
            'next_scan_at' => now()->subMinute(),
        ]);
        WatchedRepository::query()->create([
            'user_id' => 2,
            'provider' => 'github',
            'owner' => 'other',
            'repo' => 'solo',
            'url' => 'https://github.com/other/solo',
            'full_name' => 'other/solo',
            'scan_status' => WatchedRepository::STATUS_IDLE,
            'next_scan_at' => now()->subMinutes(2),
        ]);
        WatchedRepository::query()->create([
            'user_id' => 3,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'shared',
            'url' => 'https://github.com/acme/shared',
            'full_name' => 'acme/shared',
            'scan_status' => WatchedRepository::STATUS_IDLE,
            'next_scan_at' => now()->subMinutes(3),
        ]);

        $this->artisan('repo-watch:scan-repositories', ['--limit' => 10, '--force' => true])
            ->assertSuccessful();

        $pushed = [];
        Queue::assertPushed(ScanWatchedRepository::class, function (ScanWatchedRepository $job) use (&$pushed): bool {
            $pushed[] = $job->watchedRepositoryId;

            return true;
        });

        $this->assertCount(3, $pushed);

        $repos = WatchedRepository::query()->whereIn('id', $pushed)->get()->keyBy('id');
        $orderedKeys = array_map(
            fn (int $id): string => strtolower($repos[$id]->owner.'/'.$repos[$id]->repo),
            $pushed
        );

        $sharedIndexes = array_keys(array_filter($orderedKeys, fn (string $key): bool => $key === 'acme/shared'));
        $this->assertCount(2, $sharedIndexes);
        $this->assertSame(1, $sharedIndexes[1] - $sharedIndexes[0]);
    }
}
