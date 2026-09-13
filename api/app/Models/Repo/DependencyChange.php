<?php

namespace App\Models\Repo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DependencyChange extends Model
{
    use HasFactory;

    public const TYPE_ADDED = 'added';

    public const TYPE_REMOVED = 'removed';

    public const TYPE_UPDATED = 'updated';

    protected $fillable = [
        'watched_repository_id',
        'dependency_snapshot_id',
        'ecosystem',
        'manifest_path',
        'package_name',
        'change_type',
        'previous_constraint',
        'new_constraint',
        'previous_version',
        'new_version',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'watched_repository_id' => 'integer',
            'dependency_snapshot_id' => 'integer',
            'detected_at' => 'datetime',
        ];
    }

    public function watchedRepository(): BelongsTo
    {
        return $this->belongsTo(WatchedRepository::class);
    }

    public function dependencySnapshot(): BelongsTo
    {
        return $this->belongsTo(DependencySnapshot::class);
    }
}
