<?php

namespace App\Models\Repo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $scan_status
 * @property Carbon|null $last_scanned_at
 * @property Carbon|null $next_scan_at
 * @property array<string, mixed>|null $metadata
 */
class WatchedRepository extends Model
{
    use HasFactory;

    public const STATUS_IDLE = 'idle';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SCANNING = 'scanning';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'user_id',
        'provider',
        'owner',
        'repo',
        'url',
        'full_name',
        'description',
        'default_branch',
        'scan_status',
        'last_scanned_at',
        'next_scan_at',
        'last_scan_error',
        'package_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'package_count' => 'integer',
            'last_scanned_at' => 'datetime',
            'next_scan_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function watchedPackages(): HasMany
    {
        return $this->hasMany(WatchedPackage::class);
    }

    public function dependencySnapshots(): HasMany
    {
        return $this->hasMany(DependencySnapshot::class);
    }

    public function dependencyChanges(): HasMany
    {
        return $this->hasMany(DependencyChange::class);
    }

    public function displayName(): string
    {
        return $this->full_name ?: "{$this->owner}/{$this->repo}";
    }
}
