<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('watched_packages')->whereNull('manifest_path')->update(['manifest_path' => '']);

        Schema::table('watched_packages', function (Blueprint $table) {
            $table->dropUnique('watched_packages_unique');
            $table->unique(
                ['user_id', 'source_provider', 'source_owner', 'source_repo', 'ecosystem', 'package_name', 'manifest_path'],
                'watched_packages_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('watched_packages', function (Blueprint $table) {
            $table->dropUnique('watched_packages_unique');
            $table->unique(
                ['user_id', 'source_provider', 'source_owner', 'source_repo', 'ecosystem', 'package_name'],
                'watched_packages_unique'
            );
        });
    }
};
