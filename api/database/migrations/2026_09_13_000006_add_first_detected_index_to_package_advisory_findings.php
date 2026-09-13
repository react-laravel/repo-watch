<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_advisory_findings', function (Blueprint $table) {
            $table->index(
                ['watched_repository_id', 'first_detected_at'],
                'package_advisory_findings_repo_first_detected_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('package_advisory_findings', function (Blueprint $table) {
            $table->dropIndex('package_advisory_findings_repo_first_detected_idx');
        });
    }
};
