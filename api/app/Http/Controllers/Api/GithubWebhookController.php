<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RefreshRegistryPackages;
use App\Jobs\ScanWatchedRepository;
use App\Models\Repo\WatchedRepository;
use App\Services\Packages\PackageWatchRefreshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class GithubWebhookController extends Controller
{
    public function __construct(private readonly PackageWatchRefreshService $refreshService) {}

    public function repoWatch(Request $request): JsonResponse
    {
        $secret = config('services.github.webhook_secret');
        if (! is_string($secret) || trim($secret) === '') {
            return response()->json(['message' => 'Webhook secret not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (! $this->isValidSignature($request, $secret)) {
            return response()->json(['message' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        if (! in_array($request->header('X-GitHub-Event'), ['push', 'release'], true)) {
            return response()->json(['message' => 'Ignored event'], Response::HTTP_ACCEPTED);
        }

        $owner = Arr::get($request->all(), 'repository.owner.login')
            ?? Arr::get($request->all(), 'repository.owner.name');
        $repo = Arr::get($request->all(), 'repository.name');

        if (! is_string($owner) || ! is_string($repo)) {
            return response()->json(['message' => 'Missing repository information'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $registryPackageIds = $this->refreshService->registryPackageIdsForRepository($owner, $repo);
        collect($registryPackageIds)
            ->chunk(100)
            ->each(fn ($ids) => RefreshRegistryPackages::dispatch($ids->values()->all()));

        $normalizedOwner = Str::lower($owner);
        $normalizedRepo = Str::lower($repo);
        $scannedRepositories = WatchedRepository::query()
            ->where('provider', 'github')
            ->where('owner', $normalizedOwner)
            ->where('repo', $normalizedRepo)
            ->pluck('id');

        foreach ($scannedRepositories as $repositoryId) {
            ScanWatchedRepository::dispatch((int) $repositoryId, true);
        }

        return response()->json([
            'message' => 'Webhook processed',
            'refreshed_packages' => count($registryPackageIds),
            'queued_repository_scans' => $scannedRepositories->count(),
        ]);
    }

    private function isValidSignature(Request $request, string $secret): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        if (! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals(
            'sha256='.hash_hmac('sha256', $request->getContent(), $secret),
            $signature
        );
    }
}
