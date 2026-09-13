<?php

namespace Tests\Unit\Services\Packages;

use App\Models\Repo\DependencySnapshot;
use App\Models\Repo\PackageAdvisory;
use App\Models\Repo\WatchedRepository;
use App\Services\Packages\PackageAdvisoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GhsaEnrichmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedRepositoryWithLodash(): WatchedRepository
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

        DependencySnapshot::query()->create([
            'watched_repository_id' => $repository->id,
            'ecosystem' => 'npm',
            'manifest_path' => 'package.json',
            'packages_hash' => 'hash-ghsa',
            'package_count' => 1,
            'packages' => [[
                'package_name' => 'lodash',
                'normalized_current_version' => '4.17.20',
                'current_version_source' => 'lock',
            ]],
            'scanned_at' => now(),
        ]);

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    private function osvResponse(): array
    {
        return [
            'results' => [[
                'vulns' => [[
                    'id' => 'GHSA-xxxx-yyyy-zzzz',
                    'summary' => 'OSV summary for lodash',
                    'aliases' => ['CVE-2021-23337'],
                    'severity' => [['type' => 'CVSS_V3', 'score' => 7.5]],
                    'references' => [[
                        'type' => 'ADVISORY',
                        'url' => 'https://osv.dev/vulnerability/GHSA-xxxx-yyyy-zzzz',
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
        ];
    }

    public function test_it_enriches_osv_advisory_from_github_when_enabled(): void
    {
        Config::set('services.repo_watch.ghsa_enrichment_enabled', true);
        Config::set('services.github.token', 'ghp_test_token');
        Config::set('services.repo_watch.advisory_min_severity', 'high');

        $repository = $this->seedRepositoryWithLodash();

        Http::fake([
            'api.osv.dev/v1/querybatch' => Http::response($this->osvResponse(), 200),
            'api.github.com/advisories/GHSA-XXXX-YYYY-ZZZZ' => Http::response([
                'ghsa_id' => 'GHSA-xxxx-yyyy-zzzz',
                'severity' => 'critical',
                'summary' => 'GitHub-reviewed critical summary',
                'html_url' => 'https://github.com/advisories/GHSA-xxxx-yyyy-zzzz',
                'identifiers' => [
                    ['type' => 'GHSA', 'value' => 'GHSA-xxxx-yyyy-zzzz'],
                    ['type' => 'CVE', 'value' => 'CVE-2021-23337'],
                ],
                'published_at' => '2021-02-15T00:00:00Z',
            ], 200, [
                'X-RateLimit-Remaining' => ['4990'],
                'X-RateLimit-Reset' => [(string) (time() + 3600)],
                'X-RateLimit-Limit' => ['5000'],
            ]),
        ]);

        $result = app(PackageAdvisoryService::class)->refresh($repository);

        $this->assertSame(1, $result['ghsa_fetches']);
        $this->assertDatabaseHas('package_advisories', [
            'advisory_id' => 'GHSA-xxxx-yyyy-zzzz',
            'ghsa_id' => 'GHSA-XXXX-YYYY-ZZZZ',
            'severity' => PackageAdvisory::SEVERITY_CRITICAL,
            'summary' => 'GitHub-reviewed critical summary',
            'reference_url' => 'https://github.com/advisories/GHSA-xxxx-yyyy-zzzz',
        ]);

        $advisory = PackageAdvisory::query()->first();
        $this->assertNotNull($advisory?->ghsa_enriched_at);
        $this->assertContains('CVE-2021-23337', $advisory->aliases ?? []);
    }

    public function test_it_skips_github_when_enrichment_disabled(): void
    {
        Config::set('services.repo_watch.ghsa_enrichment_enabled', false);
        Config::set('services.github.token', 'ghp_test_token');

        $repository = $this->seedRepositoryWithLodash();

        Http::fake([
            'api.osv.dev/v1/querybatch' => Http::response($this->osvResponse(), 200),
            'api.github.com/*' => Http::response(['error' => 'should not call'], 500),
        ]);

        $result = app(PackageAdvisoryService::class)->refresh($repository);

        $this->assertSame(0, $result['ghsa_fetches']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.github.com'));
        $this->assertDatabaseHas('package_advisories', [
            'advisory_id' => 'GHSA-xxxx-yyyy-zzzz',
            'ghsa_id' => null,
        ]);
    }

    public function test_it_skips_github_without_token(): void
    {
        Config::set('services.repo_watch.ghsa_enrichment_enabled', true);
        Config::set('services.github.token', '');

        $repository = $this->seedRepositoryWithLodash();

        Http::fake([
            'api.osv.dev/v1/querybatch' => Http::response($this->osvResponse(), 200),
            'api.github.com/*' => Http::response(['error' => 'should not call'], 500),
        ]);

        $result = app(PackageAdvisoryService::class)->refresh($repository);

        $this->assertSame(0, $result['ghsa_fetches']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.github.com'));
    }

    public function test_it_skips_github_when_rate_limit_floor_reached(): void
    {
        Config::set('services.repo_watch.ghsa_enrichment_enabled', true);
        Config::set('services.github.token', 'ghp_test_token');
        Config::set('services.github.repo_watch_rate_limit_floor', 50);

        Cache::put('repo-watch:github-rate-limit', [
            'remaining' => 10,
            'reset' => now()->addMinutes(20)->timestamp,
            'limit' => 5000,
            'observed_at' => now()->timestamp,
        ], now()->addHour());

        $repository = $this->seedRepositoryWithLodash();

        Http::fake([
            'api.osv.dev/v1/querybatch' => Http::response($this->osvResponse(), 200),
            'api.github.com/*' => Http::response(['error' => 'should not call'], 500),
        ]);

        $result = app(PackageAdvisoryService::class)->refresh($repository);

        $this->assertSame(0, $result['ghsa_fetches']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.github.com'));
    }

    public function test_it_uses_cache_on_second_refresh(): void
    {
        Config::set('services.repo_watch.ghsa_enrichment_enabled', true);
        Config::set('services.github.token', 'ghp_test_token');
        Config::set('services.repo_watch.ghsa_enrichment_cache_ttl', 86400);

        $repository = $this->seedRepositoryWithLodash();

        Http::fake([
            'api.osv.dev/v1/querybatch' => Http::response($this->osvResponse(), 200),
            'api.github.com/advisories/GHSA-XXXX-YYYY-ZZZZ' => Http::response([
                'ghsa_id' => 'GHSA-xxxx-yyyy-zzzz',
                'severity' => 'high',
                'summary' => 'Cached GHSA summary',
                'html_url' => 'https://github.com/advisories/GHSA-xxxx-yyyy-zzzz',
                'identifiers' => [],
            ], 200, [
                'X-RateLimit-Remaining' => ['4990'],
                'X-RateLimit-Reset' => [(string) (time() + 3600)],
            ]),
        ]);

        $service = app(PackageAdvisoryService::class);
        $first = $service->refresh($repository);
        $this->assertSame(1, $first['ghsa_fetches']);

        // Second refresh: OSV upsert would overwrite severity/summary; cache re-apply
        // must restore GHSA fields without another GitHub network call.
        $second = $service->refresh($repository);
        $this->assertSame(0, $second['ghsa_fetches']);
        Http::assertSentCount(3); // 2× OSV + 1× GitHub
        $this->assertDatabaseHas('package_advisories', [
            'advisory_id' => 'GHSA-xxxx-yyyy-zzzz',
            'ghsa_id' => 'GHSA-XXXX-YYYY-ZZZZ',
            'severity' => PackageAdvisory::SEVERITY_HIGH,
            'summary' => 'Cached GHSA summary',
            'reference_url' => 'https://github.com/advisories/GHSA-xxxx-yyyy-zzzz',
        ]);
    }

    public function test_it_respects_max_fetches_per_refresh(): void
    {
        Config::set('services.repo_watch.ghsa_enrichment_enabled', true);
        Config::set('services.github.token', 'ghp_test_token');
        Config::set('services.repo_watch.ghsa_enrichment_max_per_refresh', 0);

        $repository = $this->seedRepositoryWithLodash();

        Http::fake([
            'api.osv.dev/v1/querybatch' => Http::response($this->osvResponse(), 200),
            'api.github.com/*' => Http::response(['error' => 'should not call'], 500),
        ]);

        $result = app(PackageAdvisoryService::class)->refresh($repository);

        $this->assertSame(0, $result['ghsa_fetches']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.github.com'));
        $this->assertDatabaseHas('package_advisories', [
            'advisory_id' => 'GHSA-xxxx-yyyy-zzzz',
            'severity' => PackageAdvisory::SEVERITY_HIGH,
            'ghsa_id' => null,
        ]);
    }
}
