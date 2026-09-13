<?php

namespace App\Services\Github;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GithubRepositoryWatcherService
{
    public function __construct(
        private readonly GithubRateLimitGuard $rateLimitGuard,
    ) {}

    /** @return array{0:string,1:string} */
    public function parseGithubUrl(string $url): array
    {
        $path = parse_url(trim($url), PHP_URL_PATH);
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        if (! $path || ! in_array($host, ['github.com', 'www.github.com'], true)) {
            throw new RuntimeException('请输入有效的 GitHub 仓库地址');
        }

        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        if (count($parts) < 2) {
            throw new RuntimeException('无法从地址中识别仓库 owner/repo');
        }

        return [$parts[0], preg_replace('/\.git$/', '', $parts[1]) ?: $parts[1]];
    }

    /**
     * Accept either a full GitHub URL or a shorthand `owner/repo` reference.
     *
     * @return array{0:string,1:string,2:string} owner, repo, canonical https URL
     */
    public function parseRepositoryReference(string $reference): array
    {
        $trimmed = trim($reference);

        if ($trimmed === '') {
            throw new RuntimeException('仓库引用不能为空');
        }

        if (preg_match('#^(?:https?://)?(?:www\.)?github\.com/#i', $trimmed) === 1) {
            if (! str_starts_with(strtolower($trimmed), 'http')) {
                $trimmed = 'https://'.$trimmed;
            }

            [$owner, $repo] = $this->parseGithubUrl($trimmed);

            return [$owner, $repo, sprintf('https://github.com/%s/%s', $owner, $repo)];
        }

        if (preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/?$#', $trimmed, $matches) !== 1) {
            throw new RuntimeException('请使用 owner/repo 或 GitHub 仓库 URL');
        }

        $owner = $matches[1];
        $repo = preg_replace('/\.git$/', '', $matches[2]) ?: $matches[2];

        return [$owner, $repo, sprintf('https://github.com/%s/%s', $owner, $repo)];
    }

    /** @return array<string,mixed>|null */
    public function fetchManifestFile(string $repoApi, string $path): ?array
    {
        try {
            $response = $this->githubApi()->get($repoApi.'/contents/'.$path);
            $this->rateLimitGuard->rememberFromHeaders($response->headers());
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $decoded = base64_decode((string) Arr::get($response->json(), 'content'), true);
        $raw = $decoded === false ? null : json_decode($decoded, true);

        return is_array($raw) ? $raw : null;
    }

    public function githubApi(): PendingRequest
    {
        $client = Http::timeout(15)->acceptJson()->withHeaders([
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'DogeOW Repo Watcher',
        ]);

        $token = config('services.github.token');

        return $token ? $client->withToken((string) $token) : $client;
    }
}
