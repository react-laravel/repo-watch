<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registry_packages', function (Blueprint $table) {
            $table->timestamp('last_succeeded_at')->nullable()->after('last_checked_at')->index();
        });

        Schema::table('watched_packages', function (Blueprint $table) {
            $table->string('package_name', 200)->change();
        });

        $this->normalizeRegistryPackages();
        $this->normalizeWatchedPackages();

        DB::table('registry_packages')
            ->whereNotNull('latest_version')
            ->whereNull('last_succeeded_at')
            ->update(['last_succeeded_at' => DB::raw('last_checked_at')]);
    }

    public function down(): void
    {
        $tooLong = DB::table('watched_packages')
            ->whereRaw('LENGTH(package_name) > 128')
            ->exists();

        if ($tooLong) {
            throw new RuntimeException('Cannot shrink watched package names to 128 characters.');
        }

        Schema::table('watched_packages', function (Blueprint $table) {
            $table->string('package_name', 128)->change();
        });

        Schema::table('registry_packages', function (Blueprint $table) {
            $table->dropColumn('last_succeeded_at');
        });
    }

    private function normalizeRegistryPackages(): void
    {
        $groups = DB::table('registry_packages')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $package) => strtolower(trim($package->ecosystem)).':'.strtolower(trim($package->package_name)));

        foreach ($groups as $key => $packages) {
            [$ecosystem, $packageName] = explode(':', $key, 2);
            $canonical = $packages->first();
            $best = $packages
                ->filter(fn (object $package) => is_string($package->latest_version) && $package->latest_version !== '')
                ->sortByDesc('last_checked_at')
                ->first() ?? $packages->sortByDesc('last_checked_at')->first();
            $duplicateIds = $packages->pluck('id')->reject(fn (int $id) => $id === $canonical->id)->all();

            if ($duplicateIds !== []) {
                DB::table('watched_packages')
                    ->whereIn('registry_package_id', $duplicateIds)
                    ->update(['registry_package_id' => $canonical->id]);
                DB::table('registry_packages')->whereIn('id', $duplicateIds)->delete();
            }

            DB::table('registry_packages')
                ->where('id', $canonical->id)
                ->update([
                    'ecosystem' => $ecosystem,
                    'package_name' => $packageName,
                    'latest_version' => $best->latest_version,
                    'registry_url' => $best->registry_url,
                    'last_checked_at' => $best->last_checked_at,
                    'last_error' => $best->last_error,
                    'updated_at' => now(),
                ]);
        }
    }

    private function normalizeWatchedPackages(): void
    {
        $groups = DB::table('watched_packages')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $package) => implode(':', [
                $package->user_id,
                strtolower(trim($package->source_provider)),
                strtolower(trim($package->source_owner)),
                strtolower(trim($package->source_repo)),
                strtolower(trim($package->ecosystem)),
                strtolower(trim($package->package_name)),
            ]));

        foreach ($groups as $packages) {
            $keeper = $packages->sortByDesc('updated_at')->first();
            $duplicateIds = $packages->pluck('id')->reject(fn (int $id) => $id === $keeper->id)->all();

            if ($duplicateIds !== []) {
                DB::table('watched_packages')->whereIn('id', $duplicateIds)->delete();
            }

            DB::table('watched_packages')
                ->where('id', $keeper->id)
                ->update([
                    'source_provider' => strtolower(trim($keeper->source_provider)),
                    'source_owner' => strtolower(trim($keeper->source_owner)),
                    'source_repo' => strtolower(trim($keeper->source_repo)),
                    'ecosystem' => strtolower(trim($keeper->ecosystem)),
                    'package_name' => strtolower(trim($keeper->package_name)),
                    'updated_at' => now(),
                ]);
        }
    }
};
