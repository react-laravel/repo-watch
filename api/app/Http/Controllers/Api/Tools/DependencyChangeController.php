<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Models\Repo\DependencyChange;
use App\Models\Repo\WatchedRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DependencyChangeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'repository_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ecosystem' => ['sometimes', 'nullable', 'string', Rule::in(['npm', 'composer'])],
            'change_type' => ['sometimes', 'nullable', 'string', Rule::in([
                DependencyChange::TYPE_ADDED,
                DependencyChange::TYPE_UPDATED,
                DependencyChange::TYPE_REMOVED,
            ])],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);
        $repositoryId = isset($validated['repository_id']) ? (int) $validated['repository_id'] : null;
        $ecosystem = $validated['ecosystem'] ?? null;
        $changeType = $validated['change_type'] ?? null;

        $ownedRepositoryIds = WatchedRepository::query()
            ->where('user_id', $request->user()->id)
            ->when(
                $repositoryId !== null,
                fn ($query) => $query->where('id', $repositoryId)
            )
            ->pluck('id');

        // Unknown or another user's repository_id yields an empty feed, not a leak.
        if ($ownedRepositoryIds->isEmpty()) {
            return $this->success([]);
        }

        $changes = DependencyChange::query()
            ->with('watchedRepository')
            ->whereIn('watched_repository_id', $ownedRepositoryIds)
            ->when(
                is_string($ecosystem) && $ecosystem !== '',
                fn ($query) => $query->where('ecosystem', $ecosystem)
            )
            ->when(
                is_string($changeType) && $changeType !== '',
                fn ($query) => $query->where('change_type', $changeType)
            )
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (DependencyChange $change) => $this->transformChange($change));

        return $this->success($changes);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformChange(DependencyChange $change): array
    {
        $repo = $change->watchedRepository;

        return [
            'id' => $change->id,
            'watched_repository_id' => $change->watched_repository_id,
            'repository' => $repo instanceof WatchedRepository ? [
                'id' => $repo->id,
                'full_name' => $repo->displayName(),
                'owner' => $repo->owner,
                'repo' => $repo->repo,
                'url' => $repo->url,
            ] : null,
            'ecosystem' => $change->ecosystem,
            'manifest_path' => $change->manifest_path,
            'package_name' => $change->package_name,
            'change_type' => $change->change_type,
            'previous_constraint' => $change->previous_constraint,
            'new_constraint' => $change->new_constraint,
            'previous_version' => $change->previous_version,
            'new_version' => $change->new_version,
            'detected_at' => $change->detected_at,
        ];
    }
}
