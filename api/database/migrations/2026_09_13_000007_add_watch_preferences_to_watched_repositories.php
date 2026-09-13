<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watched_repositories', function (Blueprint $table) {
            $table->timestamp('muted_at')->nullable()->after('metadata')->comment('静音时间；非空则跳过高信号通知');
            $table->string('watch_priority', 16)->default('normal')->after('muted_at')->comment('关注优先级：high/normal/low');
            $table->index(['user_id', 'watch_priority']);
        });
    }

    public function down(): void
    {
        Schema::table('watched_repositories', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'watch_priority']);
            $table->dropColumn(['muted_at', 'watch_priority']);
        });
    }
};
