<?php

namespace App\Models\Repo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $source
 * @property string $advisory_id
 * @property string|null $ghsa_id
 * @property string $ecosystem
 * @property string $package_name
 * @property string $severity
 * @property string|null $summary
 * @property list<string>|null $aliases
 * @property array<int, mixed>|null $affected_ranges
 * @property string|null $fixed_version
 * @property string|null $reference_url
 * @property Carbon|null $published_at
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $last_fetched_at
 * @property Carbon|null $ghsa_enriched_at
 */
class PackageAdvisory extends Model
{
    use HasFactory;

    public const SOURCE_OSV = 'osv';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MODERATE = 'moderate';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_UNKNOWN = 'unknown';

    protected $fillable = [
        'source',
        'advisory_id',
        'ghsa_id',
        'ecosystem',
        'package_name',
        'severity',
        'summary',
        'aliases',
        'affected_ranges',
        'fixed_version',
        'reference_url',
        'published_at',
        'withdrawn_at',
        'last_fetched_at',
        'ghsa_enriched_at',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'affected_ranges' => 'array',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'last_fetched_at' => 'datetime',
            'ghsa_enriched_at' => 'datetime',
        ];
    }

    public function findings(): HasMany
    {
        return $this->hasMany(PackageAdvisoryFinding::class);
    }

    public function isHighSignal(): bool
    {
        return in_array($this->severity, [self::SEVERITY_CRITICAL, self::SEVERITY_HIGH], true);
    }
}
