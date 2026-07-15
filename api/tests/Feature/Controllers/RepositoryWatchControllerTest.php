<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepositoryWatchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_preview_dependencies_from_repository(): void
    {
        $this->withRepoWatchIdentity();

        Http::fake([
            'https://api.github.com/repos/laravel/framework' => Http::response([
                'full_name' => 'laravel/framework',
                'name' => 'framework',
                'owner' => ['login' => 'laravel'],
                'html_url' => 'https://github.com/laravel/framework',
                'description' => 'The Laravel Framework.',
            ], 200),
            'https://api.github.com/repos/laravel/framework/contents/package.json' => Http::response([], 404),
            'https://api.github.com/repos/laravel/framework/contents/composer.json' => Http::response([
                'content' => base64_encode(json_encode([
                    'name' => 'laravel/framework',
                    'require' => [
                        'php' => '^8.2',
                        'symfony/http-foundation' => '^7.2.0',
                    ],
                    'require-dev' => [
                        'phpunit/phpunit' => '^11.5.3',
                    ],
                ])),
            ], 200),
            'https://api.github.com/repos/laravel/framework/contents/composer.lock' => Http::response([
                'content' => base64_encode(json_encode([
                    'packages' => [
                        [
                            'name' => 'symfony/http-foundation',
                            'version' => 'v7.2.1',
                        ],
                    ],
                    'packages-dev' => [
                        [
                            'name' => 'phpunit/phpunit',
                            'version' => '11.5.4',
                        ],
                    ],
                ])),
            ], 200),
        ]);

        $this->postJson('/api/repo-watch/preview', [
            'url' => 'https://github.com/laravel/framework',
        ])->assertOk()
            ->assertJsonPath('data.source.full_name', 'laravel/framework')
            ->assertJsonPath('data.manifests.0.ecosystem', 'composer')
            ->assertJsonPath('data.manifests.0.dependencies.0.package_name', 'phpunit/phpunit')
            ->assertJsonPath('data.manifests.0.dependencies.0.normalized_current_version', '11.5.4')
            ->assertJsonPath('data.manifests.0.dependencies.0.current_version_source', 'lock')
            ->assertJsonPath('data.manifests.0.dependencies.1.package_name', 'symfony/http-foundation')
            ->assertJsonPath('data.manifests.0.dependencies.1.normalized_current_version', '7.2.1');
    }

    public function test_user_can_preview_npm_dependencies_using_package_lock_versions(): void
    {
        $this->withRepoWatchIdentity();

        Http::fake([
            'https://api.github.com/repos/vercel/next.js' => Http::response([
                'full_name' => 'vercel/next.js',
                'name' => 'next.js',
                'owner' => ['login' => 'vercel'],
                'html_url' => 'https://github.com/vercel/next.js',
                'description' => 'The React Framework.',
            ], 200),
            'https://api.github.com/repos/vercel/next.js/contents/package.json' => Http::response([
                'content' => base64_encode(json_encode([
                    'name' => 'next-app',
                    'dependencies' => [
                        'react' => '^18.2.0',
                    ],
                    'devDependencies' => [
                        'typescript' => '^5.8.0',
                    ],
                ])),
            ], 200),
            'https://api.github.com/repos/vercel/next.js/contents/package-lock.json' => Http::response([
                'content' => base64_encode(json_encode([
                    'packages' => [
                        'node_modules/react' => ['version' => '18.3.1'],
                        'node_modules/typescript' => ['version' => '5.8.2'],
                    ],
                ])),
            ], 200),
            'https://api.github.com/repos/vercel/next.js/contents/composer.json' => Http::response([], 404),
        ]);

        $this->postJson('/api/repo-watch/preview', [
            'url' => 'https://github.com/vercel/next.js',
        ])->assertOk()
            ->assertJsonPath('data.manifests.0.ecosystem', 'npm')
            ->assertJsonPath('data.manifests.0.dependencies.0.package_name', 'react')
            ->assertJsonPath('data.manifests.0.dependencies.0.normalized_current_version', '18.3.1')
            ->assertJsonPath('data.manifests.0.dependencies.1.package_name', 'typescript')
            ->assertJsonPath('data.manifests.0.dependencies.1.normalized_current_version', '5.8.2');
    }

    public function test_user_can_save_refresh_and_delete_watched_packages(): void
    {
        $this->withRepoWatchIdentity();

        Http::fake([
            'https://registry.npmjs.org/react' => Http::response([
                'dist-tags' => [
                    'latest' => '19.1.0',
                ],
            ], 200),
            'https://repo.packagist.org/p2/laravel/framework.json' => Http::response([
                'packages' => [
                    'laravel/framework' => [
                        ['version_normalized' => '12.1.0.0'],
                    ],
                ],
            ], 200),
        ]);

        $createResponse = $this->postJson('/api/repo-watch/packages', [
            'source_url' => 'https://github.com/acme/demo',
            'source_owner' => 'acme',
            'source_repo' => 'demo',
            'packages' => [
                [
                    'ecosystem' => 'npm',
                    'package_name' => 'react',
                    'manifest_path' => 'package.json',
                    'current_version_constraint' => '^18.2.0',
                    'normalized_current_version' => '18.2.0',
                    'current_version_source' => 'lock',
                    'watch_level' => 'major',
                    'dependency_group' => 'dependencies',
                ],
                [
                    'ecosystem' => 'composer',
                    'package_name' => 'laravel/framework',
                    'manifest_path' => 'composer.json',
                    'current_version_constraint' => '^12.0',
                    'normalized_current_version' => '12.0.0',
                    'current_version_source' => 'lock',
                    'watch_level' => 'minor',
                    'dependency_group' => 'require',
                ],
            ],
        ])->assertCreated();

        $this->assertCount(2, $createResponse->json('data'));
        $this->assertSame('12.1.0', $createResponse->json('data.1.latest_version'));
        $firstId = $createResponse->json('data.0.id');

        $this->getJson('/api/repo-watch/packages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.current_version_source', 'lock');

        $this->postJson("/api/repo-watch/packages/{$firstId}/refresh")
            ->assertOk()
            ->assertJsonPath('data.latest_version', '19.1.0')
            ->assertJsonPath('data.latest_update_type', 'major')
            ->assertJsonPath('data.matches_preference', true);

        $this->deleteJson("/api/repo-watch/packages/{$firstId}")
            ->assertOk()
            ->assertJsonPath('message', '已取消关注');
    }

    public function test_store_rejects_mismatched_repository_source_information(): void
    {
        $this->withRepoWatchIdentity();

        $this->postJson('/api/repo-watch/packages', [
            'source_url' => 'https://github.com/acme/demo',
            'source_owner' => 'other',
            'source_repo' => 'demo',
            'packages' => [
                [
                    'ecosystem' => 'npm',
                    'package_name' => 'react',
                    'manifest_path' => 'package.json',
                    'current_version_constraint' => '^18.2.0',
                    'normalized_current_version' => '18.2.0',
                    'current_version_source' => 'lock',
                    'watch_level' => 'major',
                    'dependency_group' => 'dependencies',
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'source_url 与 source_owner/source_repo 不一致');
    }

    public function test_user_can_batch_delete_watched_packages(): void
    {
        $this->withRepoWatchIdentity();

        Http::fake([
            'https://registry.npmjs.org/react' => Http::response([
                'dist-tags' => [
                    'latest' => '19.1.0',
                ],
            ], 200),
            'https://registry.npmjs.org/typescript' => Http::response([
                'dist-tags' => [
                    'latest' => '5.8.2',
                ],
            ], 200),
        ]);

        $created = $this->postJson('/api/repo-watch/packages', [
            'source_url' => 'https://github.com/acme/demo',
            'source_owner' => 'acme',
            'source_repo' => 'demo',
            'packages' => [
                [
                    'ecosystem' => 'npm',
                    'package_name' => 'react',
                    'manifest_path' => 'package.json',
                    'current_version_constraint' => '^18.2.0',
                    'normalized_current_version' => '18.2.0',
                    'current_version_source' => 'lock',
                    'watch_level' => 'major',
                    'dependency_group' => 'dependencies',
                ],
                [
                    'ecosystem' => 'npm',
                    'package_name' => 'typescript',
                    'manifest_path' => 'package.json',
                    'current_version_constraint' => '^5.8.0',
                    'normalized_current_version' => '5.8.0',
                    'current_version_source' => 'lock',
                    'watch_level' => 'minor',
                    'dependency_group' => 'devDependencies',
                ],
            ],
        ])->assertCreated();

        $ids = collect($created->json('data'))->pluck('id')->all();

        $this->deleteJson('/api/repo-watch/packages', [
            'ids' => $ids,
        ])->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->getJson('/api/repo-watch/packages')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
