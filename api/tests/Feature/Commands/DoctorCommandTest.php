<?php

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_passes_when_production_knobs_look_sane(): void
    {
        Config::set('services.github.token', 'ghp_test_token');
        Config::set('queue.default', 'redis');
        Config::set('queue.connections.redis.queue', 'repo-watch');
        Config::set('services.repo_watch.advisory_enabled', true);
        Config::set('services.repo_watch.osv_base_url', 'https://api.osv.dev');

        $this->assertTrue(Schema::hasTable('failed_jobs'));

        $this->artisan('repo-watch:doctor')
            ->assertSuccessful();
    }

    public function test_doctor_fails_without_github_token(): void
    {
        Config::set('services.github.token', '');
        Config::set('queue.default', 'redis');
        Config::set('queue.connections.redis.queue', 'repo-watch');

        $this->artisan('repo-watch:doctor')
            ->assertFailed();
    }
}
