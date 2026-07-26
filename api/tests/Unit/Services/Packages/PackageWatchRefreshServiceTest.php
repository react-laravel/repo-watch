<?php

namespace Tests\Unit\Services\Packages;

use App\Models\Repo\WatchedPackage;
use App\Services\Packages\PackageRegistryService;
use App\Services\Packages\PackageWatchRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PackageWatchRefreshServiceTest extends TestCase
{
    use RefreshDatabase;

    private PackageWatchRefreshService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PackageWatchRefreshService(new PackageRegistryService);
    }

    #[Test]
    public function refresh_package_updates_package_with_latest_registry_info(): void
    {
        // Arrange
        $package = WatchedPackage::create([
            'user_id' => 42,
            'source_provider' => 'github',
            'source_owner' => 'lodash',
            'source_repo' => 'lodash',
            'source_url' => 'https://github.com/lodash/lodash',
            'ecosystem' => 'npm',
            'package_name' => 'lodash',
            'manifest_path' => 'package.json',
            'current_version_constraint' => '^4.17.20',
            'normalized_current_version' => '4.17.20',
            'latest_version' => '4.17.20',
            'watch_level' => 'major',
            'latest_update_type' => null,
            'registry_url' => 'https://www.npmjs.com/package/lodash',
        ]);

        Http::fake([
            'registry.npmjs.org/lodash' => Http::response([
                'dist-tags' => ['latest' => '4.17.21'],
            ], 200),
        ]);

        // Act
        $result = $this->service->refreshPackage($package);

        // Assert
        $this->assertEquals('4.17.21', $result->registryPackage->latest_version);
        $this->assertEquals(
            'patch',
            (new PackageRegistryService)->detectUpdateType(
                $result->normalized_current_version,
                $result->registryPackage->latest_version
            )
        );
        $this->assertNotNull($result->registryPackage->last_checked_at);
        $this->assertNotNull($result->registryPackage->last_succeeded_at);
        $this->assertNull($result->registryPackage->last_error);
    }

    #[Test]
    public function refresh_package_clears_last_error_on_success(): void
    {
        // Arrange
        $package = WatchedPackage::create([
            'user_id' => 42,
            'source_provider' => 'github',
            'source_owner' => 'express',
            'source_repo' => 'express',
            'source_url' => 'https://github.com/expressjs/express',
            'ecosystem' => 'npm',
            'package_name' => 'express',
            'manifest_path' => 'package.json',
            'current_version_constraint' => '^4.18.0',
            'normalized_current_version' => '4.18.0',
            'latest_version' => '4.18.0',
            'watch_level' => 'minor',
            'latest_update_type' => null,
            'registry_url' => 'https://www.npmjs.com/package/express',
            'last_error' => 'Previous error',
        ]);

        Http::fake([
            'registry.npmjs.org/express' => Http::response([
                'dist-tags' => ['latest' => '4.19.0'],
            ], 200),
        ]);

        // Act
        $result = $this->service->refreshPackage($package);

        // Assert
        $this->assertNull($result->registryPackage->last_error);
    }

    #[Test]
    public function failed_shared_registry_refresh_preserves_the_last_known_version(): void
    {
        $package = WatchedPackage::create([
            'user_id' => 1,
            'source_provider' => 'github',
            'source_owner' => 'test-owner',
            'source_repo' => 'test-repo',
            'source_url' => 'https://github.com/test-owner/test-repo',
            'ecosystem' => 'npm',
            'package_name' => 'lodash',
            'current_version_constraint' => '^4.17.20',
            'normalized_current_version' => '4.17.20',
            'latest_version' => '4.17.21',
            'watch_level' => 'patch',
            'last_checked_at' => now()->subHours(7),
        ]);

        Http::fake([
            'https://registry.npmjs.org/lodash' => Http::response([], 503),
        ]);

        $result = $this->service->refreshPackage($package);

        $this->assertSame('4.17.21', $result->registryPackage->latest_version);
        $this->assertSame(
            'Registry did not return a latest version.',
            $result->registryPackage->last_error
        );
    }

    #[Test]
    public function refresh_repository_packages_refreshes_all_packages_for_repo(): void
    {
        // Arrange
        $package1 = WatchedPackage::create([
            'user_id' => 42,
            'source_provider' => 'github',
            'source_owner' => 'test',
            'source_repo' => 'repo',
            'source_url' => 'https://github.com/test/repo',
            'ecosystem' => 'npm',
            'package_name' => 'pkg1',
            'manifest_path' => 'package.json',
            'current_version_constraint' => '^1.0.0',
            'normalized_current_version' => '1.0.0',
            'latest_version' => '1.0.0',
        ]);
        $package2 = WatchedPackage::create([
            'user_id' => 42,
            'source_provider' => 'github',
            'source_owner' => 'test',
            'source_repo' => 'repo',
            'source_url' => 'https://github.com/test/repo',
            'ecosystem' => 'npm',
            'package_name' => 'pkg2',
            'manifest_path' => 'package.json',
            'current_version_constraint' => '^2.0.0',
            'normalized_current_version' => '2.0.0',
            'latest_version' => '2.0.0',
        ]);

        Http::fake([
            'registry.npmjs.org/pkg1' => Http::response(['dist-tags' => ['latest' => '1.0.1']], 200),
            'registry.npmjs.org/pkg2' => Http::response(['dist-tags' => ['latest' => '2.0.1']], 200),
        ]);

        // Act
        $count = $this->service->refreshRepositoryPackages('test', 'repo');

        // Assert
        $this->assertEquals(2, $count);
    }

    #[Test]
    public function refresh_repository_packages_returns_count_of_refreshed_packages(): void
    {
        // Arrange
        WatchedPackage::create([
            'user_id' => 42,
            'source_provider' => 'github',
            'source_owner' => 'owner',
            'source_repo' => 'repo',
            'source_url' => 'https://github.com/owner/repo',
            'ecosystem' => 'npm',
            'package_name' => 'single-pkg',
            'manifest_path' => 'package.json',
            'current_version_constraint' => '^1.0.0',
            'normalized_current_version' => '1.0.0',
            'latest_version' => '1.0.0',
        ]);

        Http::fake([
            'registry.npmjs.org/single-pkg' => Http::response(['dist-tags' => ['latest' => '1.0.1']], 200),
        ]);

        // Act
        $count = $this->service->refreshRepositoryPackages('owner', 'repo');

        // Assert
        $this->assertEquals(1, $count);
    }

    #[Test]
    public function refresh_package_ids_only_refreshes_packages_owned_by_the_user(): void
    {
        $ownPackage = WatchedPackage::create([
            'user_id' => 42,
            'source_provider' => 'github',
            'source_owner' => 'owner',
            'source_repo' => 'repo',
            'source_url' => 'https://github.com/owner/repo',
            'ecosystem' => 'npm',
            'package_name' => 'owned-package',
            'manifest_path' => 'package.json',
            'normalized_current_version' => '1.0.0',
        ]);
        $otherPackage = WatchedPackage::create([
            'user_id' => 99,
            'source_provider' => 'github',
            'source_owner' => 'owner',
            'source_repo' => 'repo',
            'source_url' => 'https://github.com/owner/repo',
            'ecosystem' => 'npm',
            'package_name' => 'other-package',
            'manifest_path' => 'package.json',
            'normalized_current_version' => '1.0.0',
        ]);

        Http::fake([
            'registry.npmjs.org/owned-package' => Http::response([
                'dist-tags' => ['latest' => '2.0.0'],
            ], 200),
        ]);

        $count = $this->service->refreshPackageIds(42, [$ownPackage->id, $otherPackage->id]);

        $this->assertSame(1, $count);
        $this->assertSame('2.0.0', $ownPackage->fresh('registryPackage')->registryPackage->latest_version);
        $this->assertNull($otherPackage->fresh()->last_checked_at);
        Http::assertSentCount(1);
    }

    #[Test]
    public function refresh_stale_packages_refreshes_packages_not_checked_recently(): void
    {
        // Arrange
        $stalePackage = WatchedPackage::create([
            'user_id' => 42,
            'source_provider' => 'github',
            'source_owner' => 'stale',
            'source_repo' => 'repo',
            'source_url' => 'https://github.com/stale/repo',
            'ecosystem' => 'npm',
            'package_name' => 'stale-pkg',
            'manifest_path' => 'package.json',
            'current_version_constraint' => '^1.0.0',
            'normalized_current_version' => '1.0.0',
            'latest_version' => '1.0.0',
            'last_checked_at' => now()->subHours(10),
        ]);
        $freshPackage = WatchedPackage::create([
            'user_id' => 42,
            'source_provider' => 'github',
            'source_owner' => 'fresh',
            'source_repo' => 'repo',
            'source_url' => 'https://github.com/fresh/repo',
            'ecosystem' => 'npm',
            'package_name' => 'fresh-pkg',
            'manifest_path' => 'package.json',
            'current_version_constraint' => '^2.0.0',
            'normalized_current_version' => '2.0.0',
            'latest_version' => '2.0.0',
            'last_checked_at' => now()->subHours(1),
        ]);

        Http::fake([
            'registry.npmjs.org/stale-pkg' => Http::response(['dist-tags' => ['latest' => '1.0.2']], 200),
        ]);

        // Act
        $count = $this->service->refreshStalePackages(6);

        // Assert
        $this->assertEquals(1, $count);
    }

    #[Test]
    public function refresh_stale_packages_respects_custom_stale_hours(): void
    {
        // Arrange
        WatchedPackage::create([
            'user_id' => 42,
            'source_provider' => 'github',
            'source_owner' => 'custom',
            'source_repo' => 'repo',
            'source_url' => 'https://github.com/custom/repo',
            'ecosystem' => 'npm',
            'package_name' => 'custom-pkg',
            'manifest_path' => 'package.json',
            'current_version_constraint' => '^1.0.0',
            'normalized_current_version' => '1.0.0',
            'latest_version' => '1.0.0',
            'last_checked_at' => now()->subHours(10),
        ]);

        Http::fake([
            'registry.npmjs.org/custom-pkg' => Http::response(['dist-tags' => ['latest' => '1.0.1']], 200),
        ]);

        // Act - using 4 hours as custom stale threshold
        $count = $this->service->refreshStalePackages(4);

        // Assert
        $this->assertEquals(1, $count);
    }

    #[Test]
    public function refresh_stale_packages_limits_to_200_packages(): void
    {
        // Arrange
        for ($i = 0; $i < 250; $i++) {
            WatchedPackage::create([
                'user_id' => 42,
                'source_provider' => 'github',
                'source_owner' => 'limit',
                'source_repo' => "repo{$i}",
                'source_url' => "https://github.com/limit/repo{$i}",
                'ecosystem' => 'npm',
                'package_name' => "limit-pkg-{$i}",
                'manifest_path' => 'package.json',
                'current_version_constraint' => '^1.0.0',
                'normalized_current_version' => '1.0.0',
                'latest_version' => '1.0.0',
                'last_checked_at' => now()->subHours(10),
            ]);
        }

        Http::fake([
            'registry.npmjs.org/*' => Http::response(['dist-tags' => ['latest' => '1.0.1']], 200),
        ]);

        // Act
        $count = $this->service->refreshStalePackages();

        // Assert - should be limited to 200
        $this->assertLessThanOrEqual(200, $count);
    }

    #[Test]
    public function refresh_stale_packages_returns_zero_when_no_stale_packages(): void
    {
        // Arrange
        WatchedPackage::create([
            'user_id' => 42,
            'source_provider' => 'github',
            'source_owner' => 'recent',
            'source_repo' => 'repo',
            'source_url' => 'https://github.com/recent/repo',
            'ecosystem' => 'npm',
            'package_name' => 'recent-pkg',
            'manifest_path' => 'package.json',
            'current_version_constraint' => '^1.0.0',
            'normalized_current_version' => '1.0.0',
            'latest_version' => '1.0.0',
            'last_checked_at' => now()->subMinutes(30),
        ]);

        // Act
        $count = $this->service->refreshStalePackages(6);

        // Assert
        $this->assertEquals(0, $count);
    }

    #[Test]
    public function refresh_packages_does_nothing_for_empty_collection(): void
    {
        // This is tested via public interface - when no packages match, count should be 0
        $count = $this->service->refreshStalePackages(6);

        $this->assertEquals(0, $count);
    }

    #[Test]
    public function refresh_packages_updates_all_packages_with_registry_results(): void
    {
        // This is implicitly tested via other tests, but we can verify the integration
        $package = WatchedPackage::create([
            'user_id' => 42,
            'source_provider' => 'github',
            'source_owner' => 'multi',
            'source_repo' => 'repo',
            'source_url' => 'https://github.com/multi/repo',
            'ecosystem' => 'npm',
            'package_name' => 'multi-pkg',
            'manifest_path' => 'package.json',
            'current_version_constraint' => '^1.0.0',
            'normalized_current_version' => '1.0.0',
            'latest_version' => '1.0.0',
        ]);

        Http::fake([
            'registry.npmjs.org/multi-pkg' => Http::response(['dist-tags' => ['latest' => '1.5.0']], 200),
        ]);

        $count = $this->service->refreshStalePackages(6);

        $this->assertEquals(1, $count);
        $this->assertEquals('1.5.0', $package->fresh('registryPackage')->registryPackage->latest_version);
    }
}
