<?php

namespace App\Models\Repo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WatchedPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source_provider',
        'source_owner',
        'source_repo',
        'source_url',
        'ecosystem',
        'package_name',
        'manifest_path',
        'current_version_constraint',
        'normalized_current_version',
        'latest_version',
        'watch_level',
        'latest_update_type',
        'registry_url',
        'last_checked_at',
        'last_error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'metadata' => 'array',
            'user_id' => 'integer',
        ];
    }
}
