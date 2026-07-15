<?php

namespace Tests\Unit\Services\Github;

use App\Services\Github\GithubDependencyScannerService;
use App\Services\Github\GithubRepositoryWatcherService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class GithubDependencyScannerServiceTest extends TestCase
{
    private GithubDependencyScannerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GithubDependencyScannerService(new GithubRepositoryWatcherService);
        Cache::flush();
    }

    #[Test]
    public function preview_dependencies_returns_structure_with_source_and_manifests(): void
    {
        // Arrange
        Http::fake([
            'api.github.com/repos/test/project' => Http::response([
                'full_name' => 'test/project',
                'name' => 'project',
                'owner' => ['login' => 'test'],
                'html_url' => 'https://github.com/test/project',
                'description' => 'Test project',
            ], 200),
            'api.github.com/repos/test/project/contents/package.json' => Http::response([
                'content' => base64_encode('{"name": "test-pkg", "version": "1.0.0", "dependencies": {"lodash": "^4.17.21"}}'),
            ], 200),
            'api.github.com/repos/test/project/contents/package-lock.json' => Http::response([
                'content' => base64_encode('{"packages": {"node_modules/lodash": {"version": "4.17.21"}}}'),
            ], 200),
            'api.github.com/repos/test/project/contents/composer.json' => Http::response([], 404),
            'api.github.com/repos/test/project/contents/composer.lock' => Http::response([], 404),
        ]);

        // Act
        $result = $this->service->previewDependencies('https://github.com/test/project');

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('manifests', $result);
        $this->assertEquals('test/project', $result['source']['full_name']);
        $this->assertEquals('github', $result['source']['provider']);
    }

    #[Test]
    public function preview_dependencies_extracts_npm_dependencies_from_package_json(): void
    {
        // Arrange
        Http::fake([
            'api.github.com/repos/npm/pkg' => Http::response([
                'full_name' => 'npm/pkg',
                'name' => 'pkg',
                'owner' => ['login' => 'npm'],
                'html_url' => 'https://github.com/npm/pkg',
                'description' => 'NPM package',
            ], 200),
            'api.github.com/repos/npm/pkg/contents/package.json' => Http::response([
                'content' => base64_encode('{"name": "my-npm-pkg", "version": "1.0.0", "dependencies": {"express": "^4.18.0"}, "devDependencies": {"jest": "^29.0.0"}}'),
            ], 200),
            'api.github.com/repos/npm/pkg/contents/package-lock.json' => Http::response([], 404),
            'api.github.com/repos/npm/pkg/contents/composer.json' => Http::response([], 404),
            'api.github.com/repos/npm/pkg/contents/composer.lock' => Http::response([], 404),
        ]);

        // Act
        $result = $this->service->previewDependencies('https://github.com/npm/pkg');

        // Assert
        $this->assertCount(1, $result['manifests']);
        $manifest = $result['manifests'][0];
        $this->assertEquals('npm', $manifest['ecosystem']);
        $this->assertEquals('package.json', $manifest['path']);
        $this->assertEquals('my-npm-pkg', $manifest['package_name']);
        $this->assertCount(2, $manifest['dependencies']);
    }

    #[Test]
    public function preview_dependencies_extracts_composer_dependencies_from_composer_json(): void
    {
        // Arrange
        Http::fake([
            'api.github.com/repos/composer/pkg' => Http::response([
                'full_name' => 'composer/pkg',
                'name' => 'pkg',
                'owner' => ['login' => 'composer'],
                'html_url' => 'https://github.com/composer/pkg',
                'description' => 'Composer package',
            ], 200),
            'api.github.com/repos/composer/pkg/contents/package.json' => Http::response([], 404),
            'api.github.com/repos/composer/pkg/contents/composer.json' => Http::response([
                'content' => base64_encode('{"name": "my/composer-pkg", "version": "1.0.0", "require": {"laravel/framework": "^10.0"}}'),
            ], 200),
            'api.github.com/repos/composer/pkg/contents/composer.lock' => Http::response([], 404),
        ]);

        // Act
        $result = $this->service->previewDependencies('https://github.com/composer/pkg');

        // Assert
        $this->assertCount(1, $result['manifests']);
        $manifest = $result['manifests'][0];
        $this->assertEquals('composer', $manifest['ecosystem']);
        $this->assertEquals('composer.json', $manifest['path']);
        $this->assertEquals('my/composer-pkg', $manifest['package_name']);
        $this->assertCount(1, $manifest['dependencies']);
    }

    #[Test]
    public function preview_dependencies_skips_php_in_composer_dependencies(): void
    {
        // Arrange
        Http::fake([
            'api.github.com/repos/php/pkg' => Http::response([
                'full_name' => 'php/pkg',
                'name' => 'pkg',
                'owner' => ['login' => 'php'],
                'html_url' => 'https://github.com/php/pkg',
                'description' => 'PHP package',
            ], 200),
            'api.github.com/repos/php/pkg/contents/package.json' => Http::response([], 404),
            'api.github.com/repos/php/pkg/contents/composer.json' => Http::response([
                'content' => base64_encode('{"name": "my/php-pkg", "version": "1.0.0", "require": {"php": "^8.0", "laravel/framework": "^10.0"}}'),
            ], 200),
            'api.github.com/repos/php/pkg/contents/composer.lock' => Http::response([], 404),
        ]);

        // Act
        $result = $this->service->previewDependencies('https://github.com/php/pkg');

        // Assert
        $manifest = $result['manifests'][0];
        $packageNames = array_column($manifest['dependencies'], 'package_name');
        $this->assertNotContains('php', $packageNames);
        $this->assertContains('laravel/framework', $packageNames);
    }

    #[Test]
    public function preview_dependencies_throws_exception_when_no_manifests_found(): void
    {
        // Arrange
        Http::fake([
            'api.github.com/repos/empty/repo' => Http::response([
                'full_name' => 'empty/repo',
                'name' => 'repo',
                'owner' => ['login' => 'empty'],
                'html_url' => 'https://github.com/empty/repo',
                'description' => 'Empty repo',
            ], 200),
            'api.github.com/repos/empty/repo/contents/package.json' => Http::response([], 404),
            'api.github.com/repos/empty/repo/contents/composer.json' => Http::response([], 404),
        ]);

        // Act & Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('仓库中没有检测到 composer.json 或 package.json');
        $this->service->previewDependencies('https://github.com/empty/repo');
    }

    #[Test]
    public function preview_dependencies_throws_exception_when_github_api_fails(): void
    {
        // Arrange
        Http::fake([
            'api.github.com/repos/fail/repo' => Http::response(['message' => 'Not Found'], 404),
        ]);

        // Act & Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('读取 GitHub 仓库信息失败，请确认仓库存在且可公开访问');
        $this->service->previewDependencies('https://github.com/fail/repo');
    }

    #[Test]
    public function normalize_version_extracts_semver_from_constraint(): void
    {
        // Arrange
        $constraint = '^1.2.3';

        // Act
        $result = $this->service->normalizeVersion($constraint);

        // Assert
        $this->assertEquals('1.2.3', $result);
    }

    #[Test]
    public function normalize_version_handles_minor_version_constraint(): void
    {
        // Arrange
        $constraint = '^1.2';

        // Act
        $result = $this->service->normalizeVersion($constraint);

        // Assert
        $this->assertEquals('1.2.0', $result);
    }

    #[Test]
    public function normalize_version_handles_major_only_constraint(): void
    {
        // Arrange
        $constraint = '^5';

        // Act
        $result = $this->service->normalizeVersion($constraint);

        // Assert
        $this->assertEquals('5.0.0', $result);
    }

    #[Test]
    public function normalize_version_returns_null_for_invalid_constraint(): void
    {
        // Arrange
        $constraint = 'invalid';

        // Act
        $result = $this->service->normalizeVersion($constraint);

        // Assert
        $this->assertNull($result);
    }

    #[Test]
    public function normalize_version_returns_null_for_empty_constraint(): void
    {
        // Act
        $result = $this->service->normalizeVersion(null);

        // Assert
        $this->assertNull($result);
    }
}
