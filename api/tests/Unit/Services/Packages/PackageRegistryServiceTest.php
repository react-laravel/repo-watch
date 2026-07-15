<?php

namespace Tests\Unit\Services\Packages;

use App\Services\Packages\PackageRegistryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PackageRegistryServiceTest extends TestCase
{
    private PackageRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PackageRegistryService;
        Cache::flush();
    }

    #[Test]
    public function resolve_latest_returns_empty_result_for_single_package(): void
    {
        // Arrange
        Http::fake([
            'registry.npmjs.org/*' => Http::response([], 404),
        ]);

        // Act
        $result = $this->service->resolveLatest('npm', 'nonexistent-package', '1.0.0');

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('latest_version', $result);
        $this->assertArrayHasKey('registry_url', $result);
        $this->assertArrayHasKey('update_type', $result);
        $this->assertNull($result['latest_version']);
    }

    #[Test]
    public function resolve_latest_many_returns_results_from_cache_when_available(): void
    {
        // Arrange
        $cacheKey = 'repo-watch:registry:npm:lodash';
        Cache::put($cacheKey, [
            'latest_version' => '4.17.21',
            'registry_url' => 'https://www.npmjs.com/package/lodash',
        ], now()->addMinutes(30));

        // Act
        $result = $this->service->resolveLatestMany([
            [
                'ecosystem' => 'npm',
                'package_name' => 'lodash',
                'current_version' => '4.17.20',
            ],
        ]);

        // Assert
        $this->assertEquals('4.17.21', $result['npm:lodash:4.17.20']['latest_version']);
        $this->assertEquals('patch', $result['npm:lodash:4.17.20']['update_type']);
    }

    #[Test]
    public function resolve_latest_many_normalizes_cached_composer_four_segment_version(): void
    {
        // Arrange
        $cacheKey = 'repo-watch:registry:composer:symfony/dom-crawler';
        Cache::put($cacheKey, [
            'latest_version' => '8.0.8.0',
            'registry_url' => 'https://packagist.org/packages/symfony/dom-crawler',
        ], now()->addMinutes(30));

        // Act
        $result = $this->service->resolveLatestMany([
            [
                'ecosystem' => 'composer',
                'package_name' => 'symfony/dom-crawler',
                'current_version' => '8.0.0',
            ],
        ]);

        // Assert
        $this->assertEquals('8.0.8', $result['composer:symfony/dom-crawler:8.0.0']['latest_version']);
        $this->assertEquals('patch', $result['composer:symfony/dom-crawler:8.0.0']['update_type']);
        $this->assertEquals('8.0.8', Cache::get($cacheKey)['latest_version']);
    }

    #[Test]
    public function resolve_latest_many_makes_http_requests_for_uncached_packages(): void
    {
        // Arrange
        Http::fake([
            'registry.npmjs.org/lodash' => Http::response([
                'dist-tags' => ['latest' => '4.17.21'],
            ], 200),
        ]);

        // Act
        $result = $this->service->resolveLatestMany([
            [
                'ecosystem' => 'npm',
                'package_name' => 'lodash',
                'current_version' => null,
            ],
        ]);

        // Assert
        $this->assertEquals('4.17.21', $result['npm:lodash:null']['latest_version']);
        $this->assertEquals('https://www.npmjs.com/package/lodash', $result['npm:lodash:null']['registry_url']);
    }

    #[Test]
    public function resolve_latest_many_returns_empty_array_when_no_packages(): void
    {
        // Act
        $result = $this->service->resolveLatestMany([]);

        // Assert
        $this->assertEmpty($result);
    }

    #[Test]
    public function map_npm_response_returns_correct_structure(): void
    {
        // Arrange
        Http::fake([
            'registry.npmjs.org/test-pkg' => Http::response([
                'dist-tags' => ['latest' => '2.0.0'],
            ], 200),
        ]);

        // Act
        $result = $this->service->resolveLatestMany([
            [
                'ecosystem' => 'npm',
                'package_name' => 'test-pkg',
                'current_version' => '1.0.0',
            ],
        ]);

        // Assert
        $this->assertEquals('2.0.0', $result['npm:test-pkg:1.0.0']['latest_version']);
        $this->assertEquals('https://www.npmjs.com/package/test-pkg', $result['npm:test-pkg:1.0.0']['registry_url']);
        $this->assertEquals('major', $result['npm:test-pkg:1.0.0']['update_type']);
    }

    #[Test]
    public function map_npm_response_returns_fallback_url_on_failure(): void
    {
        // Arrange
        Http::fake([
            'registry.npmjs.org/fail-pkg' => Http::response([], 500),
        ]);

        // Act
        $result = $this->service->resolveLatestMany([
            [
                'ecosystem' => 'npm',
                'package_name' => 'fail-pkg',
                'current_version' => null,
            ],
        ]);

        // Assert
        $this->assertNull($result['npm:fail-pkg:null']['latest_version']);
        $this->assertEquals('https://www.npmjs.com/package/fail-pkg', $result['npm:fail-pkg:null']['registry_url']);
        $this->assertNull($result['npm:fail-pkg:null']['update_type']);
    }

    #[Test]
    public function map_composer_response_extracts_latest_version_from_packages(): void
    {
        // Arrange
        Http::fake([
            'repo.packagist.org/p2/*.json' => Http::response([
                'packages' => [
                    'vendor/package' => [
                        ['version' => 'v1.0.0', 'version_normalized' => '1.0.0.0'],
                        ['version' => '1.1.0', 'version_normalized' => '1.1.0.0'],
                        ['version' => '2.0.0', 'version_normalized' => '2.0.0.0'],
                    ],
                ],
            ], 200),
        ]);

        // Act
        $result = $this->service->resolveLatestMany([
            [
                'ecosystem' => 'composer',
                'package_name' => 'vendor/package',
                'current_version' => '1.0.0',
            ],
        ]);

        // Assert
        $this->assertEquals('2.0.0', $result['composer:vendor/package:1.0.0']['latest_version']);
        $this->assertEquals('major', $result['composer:vendor/package:1.0.0']['update_type']);
    }

    #[Test]
    public function map_composer_response_returns_fallback_url_on_failure(): void
    {
        // Arrange
        Http::fake([
            'repo.packagist.org/p2/*.json' => Http::response([], 500),
        ]);

        // Act
        $result = $this->service->resolveLatestMany([
            [
                'ecosystem' => 'composer',
                'package_name' => 'fail/package',
                'current_version' => null,
            ],
        ]);

        // Assert
        $this->assertNull($result['composer:fail/package:null']['latest_version']);
        $this->assertEquals('https://packagist.org/packages/fail/package', $result['composer:fail/package:null']['registry_url']);
    }

    #[Test]
    public function map_composer_response_handles_non_array_payload_without_crashing(): void
    {
        // Arrange
        Http::fake([
            'repo.packagist.org/p2/*.json' => Http::response('invalid payload', 200),
        ]);

        // Act
        $result = $this->service->resolveLatestMany([
            [
                'ecosystem' => 'composer',
                'package_name' => 'vendor/package',
                'current_version' => '1.0.0',
            ],
        ]);

        // Assert
        $this->assertNull($result['composer:vendor/package:1.0.0']['latest_version']);
        $this->assertEquals('https://packagist.org/packages/vendor/package', $result['composer:vendor/package:1.0.0']['registry_url']);
        $this->assertNull($result['composer:vendor/package:1.0.0']['update_type']);
    }

    #[Test]
    public function detect_update_type_returns_null_when_no_current_version(): void
    {
        // This is tested via public interface - when current_version is null, update_type should be null
        Http::fake([
            'registry.npmjs.org/test' => Http::response(['dist-tags' => ['latest' => '1.0.0']], 200),
        ]);

        $result = $this->service->resolveLatest('npm', 'test', null);

        $this->assertNull($result['update_type']);
    }

    #[Test]
    public function detect_update_type_returns_null_when_latest_is_older(): void
    {
        // Arrange
        Http::fake([
            'registry.npmjs.org/test' => Http::response(['dist-tags' => ['latest' => '1.0.0']], 200),
        ]);

        // Act
        $result = $this->service->resolveLatest('npm', 'test', '2.0.0');

        // Assert
        $this->assertNull($result['update_type']);
    }

    #[Test]
    public function detect_update_type_returns_major_for_major_version_change(): void
    {
        // Arrange
        Http::fake([
            'registry.npmjs.org/major' => Http::response(['dist-tags' => ['latest' => '3.0.0']], 200),
        ]);

        // Act
        $result = $this->service->resolveLatest('npm', 'major', '2.9.9');

        // Assert
        $this->assertEquals('major', $result['update_type']);
    }

    #[Test]
    public function detect_update_type_returns_minor_for_minor_version_change(): void
    {
        // Arrange
        Http::fake([
            'registry.npmjs.org/minor' => Http::response(['dist-tags' => ['latest' => '2.5.0']], 200),
        ]);

        // Act
        $result = $this->service->resolveLatest('npm', 'minor', '2.4.0');

        // Assert
        $this->assertEquals('minor', $result['update_type']);
    }

    #[Test]
    public function detect_update_type_returns_patch_for_patch_version_change(): void
    {
        // Arrange
        Http::fake([
            'registry.npmjs.org/patch' => Http::response(['dist-tags' => ['latest' => '2.4.5']], 200),
        ]);

        // Act
        $result = $this->service->resolveLatest('npm', 'patch', '2.4.4');

        // Assert
        $this->assertEquals('patch', $result['update_type']);
    }

    #[Test]
    public function parse_version_extracts_semver_components(): void
    {
        // This is tested indirectly via detectUpdateType which uses parseVersion
        Http::fake([
            'registry.npmjs.org/semver' => Http::response(['dist-tags' => ['latest' => '9.0.0']], 200),
        ]);

        $result = $this->service->resolveLatest('npm', 'semver', '8.5.6');

        $this->assertEquals('major', $result['update_type']);
    }

    #[Test]
    public function parse_version_returns_null_for_invalid_format(): void
    {
        // This is tested indirectly - if version cannot be parsed, no update type is detected
        Http::fake([
            'registry.npmjs.org/invalid' => Http::response(['dist-tags' => ['latest' => 'invalid']], 200),
        ]);

        $result = $this->service->resolveLatest('npm', 'invalid', 'also-invalid');

        $this->assertNull($result['update_type']);
    }
}
