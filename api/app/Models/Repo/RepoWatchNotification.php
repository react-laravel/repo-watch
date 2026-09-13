<?php

namespace App\Models\Repo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepoWatchNotification extends Model
{
    use HasFactory;

    public const TYPE_DEPENDENCY_HIGH_SIGNAL = 'dependency_high_signal';

    public const TYPE_SCAN_FAILED = 'scan_failed';

    public const TYPE_PACKAGE_ADVISORY = 'package_advisory';

    public const SEVERITY_HIGH = 'high';

    protected $fillable = [
        'user_id',
        'watched_repository_id',
        'type',
        'severity',
        'title',
        'body',
        'payload',
        'read_at',
        'webhook_delivered_at',
        'webhook_last_error',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'watched_repository_id' => 'integer',
            'payload' => 'array',
            'read_at' => 'datetime',
            'webhook_delivered_at' => 'datetime',
        ];
    }

    public function watchedRepository(): BelongsTo
    {
        return $this->belongsTo(WatchedRepository::class);
    }

    public function markRead(): void
    {
        if ($this->read_at !== null) {
            return;
        }

        $this->forceFill(['read_at' => now()])->save();
    }
}
