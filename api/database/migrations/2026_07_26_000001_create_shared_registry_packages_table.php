<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registry_packages', function (Blueprint $table) {
            $table->id();
            $table->string('ecosystem');
            $table->string('package_name', 200);
            $table->string('latest_version')->nullable();
            $table->string('registry_url', 500)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['ecosystem', 'package_name'], 'registry_packages_ecosystem_name_unique');
            $table->index('last_checked_at');
        });

        Schema::table('watched_packages', function (Blueprint $table) {
            $table->foreignId('registry_package_id')
                ->nullable()
                ->after('id')
                ->constrained('registry_packages')
                ->restrictOnDelete();
        });

        $now = now();
        $sharedPackages = [];

        DB::table('watched_packages')
            ->orderBy('id')
            ->each(function (object $watchedPackage) use (&$sharedPackages, $now): void {
                $key = $watchedPackage->ecosystem.':'.$watchedPackage->package_name;
                $existing = $sharedPackages[$key] ?? null;
                $existingCheckedAt = $existing['last_checked_at'] ?? null;
                $candidateCheckedAt = $watchedPackage->last_checked_at;

                if ($existing !== null && (
                    $candidateCheckedAt === null
                    || ($existingCheckedAt !== null && strtotime((string) $candidateCheckedAt) <= strtotime((string) $existingCheckedAt))
                )) {
                    return;
                }

                $sharedPackages[$key] = [
                    'ecosystem' => $watchedPackage->ecosystem,
                    'package_name' => $watchedPackage->package_name,
                    'latest_version' => $watchedPackage->latest_version,
                    'registry_url' => $watchedPackage->registry_url,
                    'last_checked_at' => $watchedPackage->last_checked_at,
                    'last_error' => $watchedPackage->last_error,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            });

        foreach (array_chunk(array_values($sharedPackages), 500) as $packageChunk) {
            DB::table('registry_packages')->insertOrIgnore($packageChunk);
        }

        DB::table('registry_packages')
            ->select(['id', 'ecosystem', 'package_name'])
            ->orderBy('id')
            ->each(function (object $registryPackage): void {
                DB::table('watched_packages')
                    ->where('ecosystem', $registryPackage->ecosystem)
                    ->where('package_name', $registryPackage->package_name)
                    ->update(['registry_package_id' => $registryPackage->id]);
            });
    }

    public function down(): void
    {
        DB::table('registry_packages')
            ->orderBy('id')
            ->each(function (object $registryPackage): void {
                DB::table('watched_packages')
                    ->where('registry_package_id', $registryPackage->id)
                    ->orderBy('id')
                    ->each(function (object $watchedPackage) use ($registryPackage): void {
                        DB::table('watched_packages')
                            ->where('id', $watchedPackage->id)
                            ->update([
                                'latest_version' => $registryPackage->latest_version,
                                'latest_update_type' => $this->detectUpdateType(
                                    $watchedPackage->normalized_current_version,
                                    $registryPackage->latest_version
                                ),
                                'registry_url' => $registryPackage->registry_url,
                                'last_checked_at' => $registryPackage->last_checked_at,
                                'last_error' => $registryPackage->last_error,
                            ]);
                    });
            });

        Schema::table('watched_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registry_package_id');
        });

        Schema::dropIfExists('registry_packages');
    }

    private function detectUpdateType(?string $currentVersion, ?string $latestVersion): ?string
    {
        $current = $this->parseVersion($currentVersion);
        $latest = $this->parseVersion($latestVersion);

        if ($current === null || $latest === null || version_compare($latestVersion, $currentVersion, '<=')) {
            return null;
        }

        if ($latest[0] !== $current[0]) {
            return 'major';
        }

        if ($latest[1] !== $current[1]) {
            return 'minor';
        }

        return $latest[2] !== $current[2] ? 'patch' : null;
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function parseVersion(?string $version): ?array
    {
        if (! is_string($version) || preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
    }
};
