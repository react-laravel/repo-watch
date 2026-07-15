<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function withRepoWatchIdentity(int $id = 42): static
    {
        return $this->withSession([
            'repo_watch_identity' => [
                'id' => $id,
                'name' => 'Repo Watch Tester',
                'email' => 'repo-watch@example.com',
                'is_admin' => false,
                'permissions' => [],
            ],
        ]);
    }
}
