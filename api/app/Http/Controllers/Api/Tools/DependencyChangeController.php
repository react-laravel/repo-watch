<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Models\Repo\DependencyChange;
use App\Models\Repo\WatchedRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DependencyChangeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', 50)));
        $repositoryIds = WatchedRepository::query()
            ->where('user_id', $request->user()->id)
            ->pluck('id');

        $changes = DependencyChange::query()
            ->with('watchedRepository')
            ->whereIn('watched_repository_id', $repositoryIds)
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (DependencyChange $change) {
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
            });

        return $this->success($changes);
    }
}
