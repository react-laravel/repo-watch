<?php

namespace Tests\Unit\Services\Packages;

use App\Jobs\DeliverRepoWatchNotificationWebhook;
use App\Models\Repo\DependencySnapshot;
use App\Models\Repo\PackageAdvisory;
use App\Models\Repo\PackageAdvisoryFinding;
use App\Models\Repo\RepoWatchNotification;
use App\Models\Repo\WatchedRepository;
use App\Services\Packages\PackageAdvisoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PackageAdvisoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_upserts_osv_findings_and_notifies_for_critical_high_only(): void
    {
        Queue::fake();
        Config::set('services.repo_watch.notify_webhook_url', 'https://hooks.example.test/repo-watch');
        Config::set('services.repo_watch.advisory_min_severity', 'high');
        Config::set('services.repo_watch.notify_on_advisory', true);

        $repository = WatchedRepository::query()->create([
            'user_id' => 42,
            'provider' => 'github',
            'owner' => 'acme',
            'repo' => 'demo',
            'url' => 'https://github.com/acme/demo',
            'full_name' => 'acme/demo',
            'scan_status' => WatchedRepository::STATUS_IDLE,
        ]);

        DependencySnapshot::query()->create([
            'watched_repository_id' => $repository->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'packages_hash' => 'hash-1',
            'package_count' => 2,
            'packages' => [
                [
                    'package_name' => 'lodash',
                    'normalized_current_version' => '4.17.20',
                    'current_version_source' => 'lock',
                ],
                [
                    'package_name' => 'left-pad',
                    'normalized_current_version' => '1.0.0',
                    'current_version_source' => 'manifest',
                ],
            ],
            'scanned_at' => now(),
        ]);

        Http::fake([
            'api.osv.dev/v1/querybatch' => Http::response([
                'results' => [[
                    'vulns' => [[
                        'id' => 'GHSA-xxxx-yyyy-zzzz',
                        'summary' => 'Prototype pollution in lodash',
                        'aliases' => ['CVE-2021-23337'],
                        'severity' => [['type' => 'CVSS_V3', 'score' => 7.5]],
                        'references' => [[
                            'type' => 'ADVISORY',
                            'url' => 'https://github.com/advisories/GHSA-xxxx-yyyy-zzzz',
                        ]],
                        'affected' => [[
                            'package' => ['ecosystem' => 'npm', 'name' => 'lodash'],
                            'ranges' => [[
                                'type' => 'SEMVER',
                                'events' => [
                                    ['introduced' => '0'],
                                    ['fixed' => '4.17.21'],
                                ],
                            ]],
                        ]],
                    ]],
                ]],
            ], 200),
        ]);

        $result = app(PackageAdvisoryService::class)->refresh($repository);

        $this->assertSame(1, $result['packages_queried']);
        $this->assertSame(1, $result['findings_open']);
        $this->assertDatabaseHas('package_advisories', [
            'advisory_id' => 'GHSA-xxxx-yyyy-zzzz',
            'package_name' => 'lodash',
            'severity' => PackageAdvisory::SEVERITY_HIGH,
            'fixed_version' => '4.17.21',
        ]);
        $this->assertDatabaseHas('package_advisory_findings', [
            'watched_repository_id' => $repository->id,
            'package_name' => 'lodash',
            'installed_version' => '4.17.20',
            'status' => PackageAdvisoryFinding::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('repo_watch_notifications', [
            'user_id' => 42,
            'type' => RepoWatchNotification::TYPE_PACKAGE_ADVISORY,
        ]);
        Queue::assertPushed(DeliverRepoWatchNotificationWebhook::class);
    }

    public function test_map_osv_severity_from_github_database_specific(): void
    {
        $service = app(PackageAdvisoryService::class);

        $this->assertSame(
            PackageAdvisory::SEVERITY_CRITICAL,
            $service->mapOsvSeverity([
                'database_specific' => ['severity' => 'critical'],
            ])
        );
        $this->assertSame(
            PackageAdvisory::SEVERITY_MODERATE,
            $service->mapOsvSeverity([
                'database_specific' => ['severity' => 'medium'],
            ])
        );
        $this->assertSame('Packagist', $service->toOsvEcosystem('composer'));
    }
}
