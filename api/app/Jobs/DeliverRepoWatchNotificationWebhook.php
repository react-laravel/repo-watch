<?php

namespace App\Jobs;

use App\Models\Repo\RepoWatchNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverRepoWatchNotificationWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly int $notificationId,
    ) {}

    public function handle(): void
    {
        $url = config('services.repo_watch.notify_webhook_url');

        if (! is_string($url) || trim($url) === '') {
            return;
        }

        $notification = RepoWatchNotification::query()->find($this->notificationId);

        if (! $notification instanceof RepoWatchNotification) {
            return;
        }

        if ($notification->webhook_delivered_at !== null) {
            return;
        }

        $payload = [
            'text' => $notification->title,
            'event' => $notification->type,
            'severity' => $notification->severity,
            'title' => $notification->title,
            'body' => $notification->body,
            'payload' => $notification->payload,
            'created_at' => optional($notification->created_at)?->toIso8601String(),
        ];

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);

            if ($response->failed()) {
                $notification->forceFill([
                    'webhook_last_error' => sprintf('HTTP %s: %s', $response->status(), $response->body()),
                ])->save();

                $response->throw();
            }

            $notification->forceFill([
                'webhook_delivered_at' => now(),
                'webhook_last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('repo-watch notification webhook failed', [
                'notification_id' => $notification->id,
                'error' => $exception->getMessage(),
            ]);

            $notification->forceFill([
                'webhook_last_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }
}
