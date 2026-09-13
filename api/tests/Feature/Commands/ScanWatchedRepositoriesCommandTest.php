<?php

namespace Tests\Feature\Commands;

use App\Jobs\ScanWatchedRepository;
use App\Models\Repo\WatchedRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
