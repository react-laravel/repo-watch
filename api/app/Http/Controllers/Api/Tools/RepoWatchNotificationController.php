<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Models\Repo\RepoWatchNotification;
use App\Models\Repo\WatchedRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RepoWatchNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', 30)));
        $unreadOnly = filter_var($request->query('unread_only', false), FILTER_VALIDATE_BOOL);

        $query = RepoWatchNotification::query()
            ->where('user_id', $request->user()->id)
            ->with('watchedRepository')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $notifications = $query->limit($limit)->get();

        $unreadCount = RepoWatchNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return $this->success([
            'notifications' => $notifications
                ->map(fn (RepoWatchNotification $notification) => $this->transform($notification))
                ->all(),
            'unread_count' => $unreadCount,
            'policy' => [
                'enabled' => (bool) config('services.repo_watch.notify_enabled', true),
                'webhook_configured' => filled(config('services.repo_watch.notify_webhook_url')),
                'on_major' => (bool) config('services.repo_watch.notify_on_major', true),
                'on_removed' => (bool) config('services.repo_watch.notify_on_removed', true),
                'on_scan_failure' => (bool) config('services.repo_watch.notify_on_scan_failure', true),
                'on_advisory' => (bool) config('services.repo_watch.notify_on_advisory', true),
            ],
        ]);
    }

    public function markRead(Request $request, RepoWatchNotification $repoWatchNotification): JsonResponse
    {
        if ((int) $repoWatchNotification->user_id !== (int) $request->user()->id) {
            return $this->error('无权操作该通知', null, 403);
        }

        $repoWatchNotification->markRead();

        return $this->success(
            $this->transform($repoWatchNotification->fresh() ?? $repoWatchNotification),
            '已标记为已读'
        );
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = RepoWatchNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->success([
            'marked' => $updated,
        ], sprintf('已标记 %d 条通知为已读', $updated));
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(RepoWatchNotification $notification): array
    {
        $repository = $notification->watchedRepository;

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'severity' => $notification->severity,
            'title' => $notification->title,
            'body' => $notification->body,
            'payload' => $notification->payload,
            'read_at' => $notification->read_at,
            'webhook_delivered_at' => $notification->webhook_delivered_at,
            'created_at' => $notification->created_at,
            'repository' => $repository instanceof WatchedRepository ? [
                'id' => $repository->id,
                'full_name' => $repository->displayName(),
                'url' => $repository->url,
            ] : ($notification->payload['repository'] ?? null),
        ];
    }
}
