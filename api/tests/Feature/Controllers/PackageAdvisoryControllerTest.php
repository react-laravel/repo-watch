<?php

namespace Tests\Feature\Controllers;

use App\Models\Repo\PackageAdvisory;
use App\Models\Repo\PackageAdvisoryFinding;
use App\Models\Repo\WatchedRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageAdvisoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_open_advisory_findings_for_owned_repositories(): void
    {
        $this->withRepoWatchIdentity();

        $mine = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'full_name' => 'acme/demo',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        $theirs = WatchedRepository::query()->create([
            'user_id' => 99,
            'provider' => 'github',
            'owner' => 'other',
            'repo' => 'secret',
            'url' => 'https://github.com/other/secret',
            'full_name' => 'other/secret',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        $advisory = PackageAdvisory::query()->create([
            'source' => PackageAdvisory::SOURCE_OSV,
            'advisory_id' => 'GHSA-test-0001',
            'ecosystem' => 'npm',
            'package_name' => 'lodash',
            'severity' => PackageAdvisory::SEVERITY_HIGH,
            'summary' => 'Test advisory',
            'aliases' => ['CVE-2020-0000'],
            'reference_url' => 'https://github.com/advisories/GHSA-test-0001',
            'last_fetched_at' => now(),
        ]);

        PackageAdvisoryFinding::query()->create([
            'watched_repository_id' => $mine->id,
            'package_advisory_id' => $advisory->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'package_name' => 'lodash',
            'installed_version' => '4.17.20',
            'status' => PackageAdvisoryFinding::STATUS_OPEN,
            'first_detected_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        PackageAdvisoryFinding::query()->create([
            'watched_repository_id' => $theirs->id,
            'package_advisory_id' => $advisory->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'package_name' => 'lodash',
            'installed_version' => '4.17.20',
            'status' => PackageAdvisoryFinding::STATUS_OPEN,
            'first_detected_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        $this->getJson('/api/repo-watch/advisories')
            ->assertOk()
            ->assertJsonCount(1, 'data.findings')
            ->assertJsonPath('data.findings.0.package_name', 'lodash')
            ->assertJsonPath('data.findings.0.advisory.advisory_id', 'GHSA-test-0001')
            ->assertJsonPath('data.policy.min_severity', 'high');
    }

    public function test_advisories_exclude_muted_repos_by_default(): void
    {
        $this->withRepoWatchIdentity();

        $active = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'active',
            'url' => 'https://github.com/acme/active',
            'full_name' => 'acme/active',
            'scan_status' => WatchedRepository::STATUS_IDLE,
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
        ]);

        $advisory = PackageAdvisory::query()->create([
            'source' => PackageAdvisory::SOURCE_OSV,
            'advisory_id' => 'GHSA-mute-0001',
            'ecosystem' => 'npm',
            'package_name' => 'lodash',
            'severity' => PackageAdvisory::SEVERITY_HIGH,
            'summary' => 'Muted filter advisory',
            'aliases' => [],
            'reference_url' => 'https://github.com/advisories/GHSA-mute-0001',
            'last_fetched_at' => now(),
        ]);

        PackageAdvisoryFinding::query()->create([
            'watched_repository_id' => $active->id,
            'package_advisory_id' => $advisory->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'package_name' => 'lodash',
            'installed_version' => '4.17.20',
            'status' => PackageAdvisoryFinding::STATUS_OPEN,
            'first_detected_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);
        PackageAdvisoryFinding::query()->create([
            'watched_repository_id' => $muted->id,
            'package_advisory_id' => $advisory->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'package_name' => 'lodash',
            'installed_version' => '4.17.20',
            'status' => PackageAdvisoryFinding::STATUS_OPEN,
            'first_detected_at' => now()->subDay(),
            'last_seen_at' => now()->subHour(),
        ]);

        $this->getJson('/api/repo-watch/advisories')
            ->assertOk()
            ->assertJsonCount(1, 'data.findings')
            ->assertJsonPath('data.findings.0.repository.full_name', 'acme/active')
            ->assertJsonPath('data.findings.0.repository.muted', false);

        $this->getJson('/api/repo-watch/advisories?include_muted=1')
            ->assertOk()
            ->assertJsonCount(2, 'data.findings')
            ->assertJsonPath('data.findings.0.repository.muted', false)
            ->assertJsonPath('data.findings.1.repository.muted', true);

        $this->getJson('/api/repo-watch/advisories?repository_id='.$muted->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.findings')
            ->assertJsonPath('data.findings.0.repository.full_name', 'acme/muted')
            ->assertJsonPath('data.findings.0.repository.muted', true);
    }
}
