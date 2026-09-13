<?php

namespace Tests\Unit\Services\Github;

use App\Services\Github\GithubRateLimitGuard;
use App\Services\Github\GithubRepositoryWatcherService;
use RuntimeException;
use Tests\TestCase;

class GithubRepositoryWatcherServiceTest extends TestCase
{
    public function test_parse_repository_reference_accepts_owner_repo_and_urls(): void
    {
        $service = new GithubRepositoryWatcherService(app(GithubRateLimitGuard::class));

        $this->assertSame(
            ['acme', 'demo', 'https://github.com/acme/demo'],
            $service->parseRepositoryReference('acme/demo')
        );
        $this->assertSame(
            ['acme', 'demo', 'https://github.com/acme/demo'],
            $service->parseRepositoryReference('https://github.com/acme/demo.git')
        );
        $this->assertSame(
            ['acme', 'demo', 'https://github.com/acme/demo'],
            $service->parseRepositoryReference('github.com/acme/demo')
        );
    }

    public function test_parse_repository_reference_rejects_invalid_input(): void
    {
        $service = new GithubRepositoryWatcherService(app(GithubRateLimitGuard::class));

        $this->expectException(RuntimeException::class);
        $service->parseRepositoryReference('only-owner');
    }
}
