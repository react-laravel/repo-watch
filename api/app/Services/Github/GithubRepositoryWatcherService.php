<?php

namespace App\Services\Github;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GithubRepositoryWatcherService
{
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

    /** @return array<string,mixed>|null */
    public function fetchManifestFile(string $repoApi, string $path): ?array
    {
        try {
            $response = $this->githubApi()->get($repoApi.'/contents/'.$path);
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
