<?php

namespace App\Models\Repo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $watched_repository_id
 * @property string $ecosystem
 * @property string $manifest_path
 * @property array<int, array<string, mixed>>|null $packages
 * @property Carbon|null $scanned_at
 */
class DependencySnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'watched_repository_id',
        'ecosystem',
        'manifest_path',
        'packages_hash',
        'packages',
        'package_count',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'watched_repository_id' => 'integer',
            'package_count' => 'integer',
            'packages' => 'array',
            'scanned_at' => 'datetime',
        ];
    }

    public function watchedRepository(): BelongsTo
    {
        return $this->belongsTo(WatchedRepository::class);
    }

    public function dependencyChanges(): HasMany
    {
        return $this->hasMany(DependencyChange::class);
    }
}
