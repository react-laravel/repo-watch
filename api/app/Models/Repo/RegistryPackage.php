<?php

namespace App\Models\Repo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegistryPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'ecosystem',
        'package_name',
        'latest_version',
        'registry_url',
        'last_checked_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
        ];
    }

    public function watchedPackages(): HasMany
    {
        return $this->hasMany(WatchedPackage::class);
    }
}
