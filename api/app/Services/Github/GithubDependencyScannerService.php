<?php

namespace App\Services\Github;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class GithubDependencyScannerService
{
    public function __construct(
        private readonly GithubRepositoryWatcherService $repositoryService
    ) {}

    /**
     * @return array{
     *   source: array<string, mixed>,
     *   manifests: array<int, array{ecosystem: string, path: string, package_name: ?string, dependencies: array<int, array<string, mixed>>}>
     * }
     */
    public function previewDependencies(string $url): array
    {
        [$owner, $repo] = $this->repositoryService->parseGithubUrl($url);

        return Cache::remember(
            sprintf('repo-watch:preview:%s/%s', strtolower($owner), strtolower($repo)),
            now()->addMinutes(10),
            function () use ($owner, $repo, $url): array {
                $repoApi = sprintf('https://api.github.com/repos/%s/%s', rawurlencode($owner), rawurlencode($repo));
                $repoResponse = $this->repositoryService->githubApi()->get($repoApi);

                if ($repoResponse->failed()) {
                    throw new RuntimeException('读取 GitHub 仓库信息失败，请确认仓库存在且可公开访问');
                }

                $repoPayload = $repoResponse->json();
                $manifests = [];

                foreach ([
                    ['path' => 'package.json', 'ecosystem' => 'npm', 'dependency_keys' => ['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies']],
                    ['path' => 'composer.json', 'ecosystem' => 'composer', 'dependency_keys' => ['require', 'require-dev']],
                ] as $manifestConfig) {
                    $manifest = $this->repositoryService->fetchManifestFile($repoApi, $manifestConfig['path']);
                    if (! $manifest) {
                        continue;
                    }

                    $lockedVersions = $this->resolveLockedVersions($repoApi, $manifestConfig['ecosystem']);

                    $dependencies = [];
                    foreach ($manifestConfig['dependency_keys'] as $dependencyKey) {
                        foreach ((array) Arr::get($manifest, $dependencyKey, []) as $packageName => $constraint) {
                            if (! is_string($packageName) || ! is_string($constraint)) {
                                continue;
                            }

                            if ($manifestConfig['ecosystem'] === 'composer' && $packageName === 'php') {
                                continue;
                            }

                            $lockedVersion = $lockedVersions[$packageName] ?? null;

                            $dependencies[] = [
                                'package_name' => $packageName,
                                'current_version_constraint' => $constraint,
                                'normalized_current_version' => $lockedVersion ?? $this->normalizeVersion($constraint),
                                'current_version_source' => $lockedVersion ? 'lock' : 'manifest',
                                'dependency_group' => $dependencyKey,
                            ];
                        }
                    }

                    usort($dependencies, fn ($a, $b) => strcmp($a['package_name'], $b['package_name']));

                    $manifests[] = [
                        'ecosystem' => $manifestConfig['ecosystem'],
                        'path' => $manifestConfig['path'],
                        'package_name' => Arr::get($manifest, 'name'),
                        'dependencies' => $dependencies,
                    ];
                }

                if ($manifests === []) {
                    throw new RuntimeException('仓库中没有检测到 composer.json 或 package.json');
                }

                return [
                    'source' => [
                        'provider' => 'github',
                        'owner' => Arr::get($repoPayload, 'owner.login', $owner),
                        'repo' => Arr::get($repoPayload, 'name', $repo),
                        'full_name' => Arr::get($repoPayload, 'full_name', "{$owner}/{$repo}"),
                        'html_url' => Arr::get($repoPayload, 'html_url', $url),
                        'description' => Arr::get($repoPayload, 'description'),
                    ],
                    'manifests' => $manifests,
                ];
            }
        );
    }

    public function normalizeVersion(?string $constraint): ?string
    {
        if (! $constraint) {
            return null;
        }

        if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $constraint, $matches) === 1) {
            return "{$matches[1]}.{$matches[2]}.{$matches[3]}";
        }

        if (preg_match('/(\d+)\.(\d+)/', $constraint, $matches) === 1) {
            return "{$matches[1]}.{$matches[2]}.0";
        }

        if (preg_match('/(\d+)/', $constraint, $matches) === 1) {
            return "{$matches[1]}.0.0";
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function resolveLockedVersions(string $repoApi, string $ecosystem): array
    {
        return match ($ecosystem) {
            'npm' => $this->extractNpmLockedVersions($this->repositoryService->fetchManifestFile($repoApi, 'package-lock.json')),
            'composer' => $this->extractComposerLockedVersions($this->repositoryService->fetchManifestFile($repoApi, 'composer.lock')),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>|null  $lockFile
     * @return array<string, string>
     */
    private function extractNpmLockedVersions(?array $lockFile): array
    {
        if (! $lockFile) {
            return [];
        }

        $versions = [];

        foreach ((array) Arr::get($lockFile, 'packages', []) as $packagePath => $packageInfo) {
            if (! is_string($packagePath) || ! is_array($packageInfo) || ! str_starts_with($packagePath, 'node_modules/')) {
                continue;
            }

            $packageName = substr($packagePath, strlen('node_modules/'));
            $version = Arr::get($packageInfo, 'version');

            if (is_string($version) && $packageName !== '') {
                $versions[$packageName] = $version;
            }
        }

        foreach ((array) Arr::get($lockFile, 'dependencies', []) as $packageName => $packageInfo) {
            if (! is_string($packageName) || isset($versions[$packageName]) || ! is_array($packageInfo)) {
                continue;
            }

            $version = Arr::get($packageInfo, 'version');
            if (is_string($version)) {
                $versions[$packageName] = $version;
            }
        }

        return $versions;
    }

    /**
     * @param  array<string, mixed>|null  $lockFile
     * @return array<string, string>
     */
    private function extractComposerLockedVersions(?array $lockFile): array
    {
        if (! $lockFile) {
            return [];
        }

        $versions = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ((array) Arr::get($lockFile, $section, []) as $packageInfo) {
                if (! is_array($packageInfo)) {
                    continue;
                }

                $packageName = Arr::get($packageInfo, 'name');
                $version = Arr::get($packageInfo, 'version');

                if (is_string($packageName) && is_string($version)) {
                    $versions[$packageName] = ltrim($version, 'v');
                }
            }
        }

        return $versions;
    }
}
