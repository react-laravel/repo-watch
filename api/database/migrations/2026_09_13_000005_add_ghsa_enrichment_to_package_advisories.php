<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_advisories', function (Blueprint $table) {
            $table->string('ghsa_id')->nullable()->after('advisory_id');
            $table->timestamp('ghsa_enriched_at')->nullable()->after('last_fetched_at');
            $table->index('ghsa_id');
        });
    }

    public function down(): void
    {
        Schema::table('package_advisories', function (Blueprint $table) {
            $table->dropIndex(['ghsa_id']);
            $table->dropColumn(['ghsa_id', 'ghsa_enriched_at']);
        });
    }
};
