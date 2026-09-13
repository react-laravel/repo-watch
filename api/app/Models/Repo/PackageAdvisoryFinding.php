<?php

namespace App\Models\Repo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $watched_repository_id
 * @property int $package_advisory_id
 * @property int|null $dependency_snapshot_id
 * @property string $ecosystem
 * @property string $manifest_path
 * @property string $package_name
 * @property string $installed_version
 * @property string $status
 * @property Carbon|null $first_detected_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $resolved_at
 * @property-read PackageAdvisory|null $packageAdvisory
 */
class PackageAdvisoryFinding extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'watched_repository_id',
        'package_advisory_id',
        'dependency_snapshot_id',
        'ecosystem',
        'manifest_path',
        'package_name',
        'installed_version',
        'status',
        'first_detected_at',
        'last_seen_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'watched_repository_id' => 'integer',
            'package_advisory_id' => 'integer',
            'dependency_snapshot_id' => 'integer',
            'first_detected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function watchedRepository(): BelongsTo
    {
        return $this->belongsTo(WatchedRepository::class);
    }

    /**
     * @return BelongsTo<PackageAdvisory, $this>
     */
    public function packageAdvisory(): BelongsTo
    {
        return $this->belongsTo(PackageAdvisory::class);
    }

    public function dependencySnapshot(): BelongsTo
    {
        return $this->belongsTo(DependencySnapshot::class);
    }
}
