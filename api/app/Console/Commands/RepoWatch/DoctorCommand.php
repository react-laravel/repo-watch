<?php

namespace App\Console\Commands\RepoWatch;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'repo-watch:doctor';

    protected $description = 'Check production readiness for Repo Watch scan/notify/advisory stack';

    public function handle(): int
    {
        $failures = 0;

        $failures += $this->check('Database connection', function (): string {
            DB::connection()->getPdo();

            return DB::connection()->getDatabaseName() ?: 'ok';
        });

        $failures += $this->check('failed_jobs table', function (): string {
            if (! Schema::hasTable('failed_jobs')) {
                throw new \RuntimeException('missing — run php artisan migrate');
            }

            return 'present';
        });

        $failures += $this->check('GitHub token', function (): string {
            $token = config('services.github.token');
            if (! is_string($token) || trim($token) === '') {
                throw new \RuntimeException('GITHUB_TOKEN (or GITHUB_PAT) is empty — required for 20–30 repos');
            }

            return 'configured';
        });

        $failures += $this->check('Queue connection', function (): string {
            $connection = (string) config('queue.default');
            $queue = (string) config('queue.connections.'.$connection.'.queue', 'default');

            if ($connection === 'sync') {
                throw new \RuntimeException('QUEUE_CONNECTION=sync will not run background scans in production');
            }

            return "{$connection} / queue={$queue}";
        });

        $failures += $this->check('Scheduled commands', function (): string {
            // Read the schedule source instead of `schedule:list`, which may require
            // Redis for withoutOverlapping mutexes even when only inspecting.
            $path = base_path('routes/console.php');
            $contents = is_file($path) ? (string) file_get_contents($path) : '';

            $expected = [
                'repo-watch:refresh',
                'repo-watch:scan-repositories',
                'repo-watch:refresh-advisories',
                'repo-watch:prune-snapshots',
            ];

            $missing = array_values(array_filter(
                $expected,
                fn (string $command): bool => ! str_contains($contents, $command)
            ));

            if ($missing !== []) {
                throw new \RuntimeException('missing from routes/console.php: '.implode(', ', $missing));
            }

            return 'scan / advisories / prune / refresh registered';
        });

        $failures += $this->check('Advisory OSV base URL', function (): string {
            $url = config('services.repo_watch.osv_base_url');
            if (! is_string($url) || ! str_starts_with($url, 'http')) {
                throw new \RuntimeException('REPO_WATCH_OSV_BASE_URL is invalid');
            }

            $enabled = filter_var(config('services.repo_watch.advisory_enabled'), FILTER_VALIDATE_BOOL);

            return ($enabled ? 'enabled' : 'disabled')." ({$url})";
        });

        $notifyEnabled = filter_var(config('services.repo_watch.notify_enabled'), FILTER_VALIDATE_BOOL);
        $webhook = config('services.repo_watch.notify_webhook_url');
        if ($notifyEnabled && (! is_string($webhook) || trim($webhook) === '')) {
            $this->warn('Notifications: in-app only (REPO_WATCH_NOTIFY_WEBHOOK_URL empty)');
        } else {
            $this->info('Notifications: '.($notifyEnabled ? 'enabled' : 'disabled')
                .(is_string($webhook) && trim($webhook) !== '' ? ' + webhook' : ''));
        }

        $this->newLine();
        if ($failures > 0) {
            $this->error("Doctor found {$failures} blocking issue(s).");

            return self::FAILURE;
        }

        $this->info('Doctor OK — stack looks shippable. Still run go-live checklist in docs/multi-repo-scaling.md.');

        return self::SUCCESS;
    }

    /**
     * @param  callable(): string  $callback
     */
    private function check(string $label, callable $callback): int
    {
        try {
            $detail = $callback();
            $this->info("✓ {$label}: {$detail}");

            return 0;
        } catch (Throwable $exception) {
            $this->error("✗ {$label}: {$exception->getMessage()}");

            return 1;
        }
    }
}
