<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repo_watch_notifications', function (Blueprint $table) {
            $table->id()->comment('记录 ID');
            $table->unsignedBigInteger('user_id')->comment('所属用户 ID（SSO identity）');
            $table->foreignId('watched_repository_id')
                ->nullable()
                ->constrained('watched_repositories')
                ->nullOnDelete();
            $table->string('type', 64)->comment('通知类型');
            $table->string('severity', 32)->default('high')->comment('信号强度：high/medium');
            $table->string('title', 255)->comment('标题');
            $table->text('body')->comment('摘要正文');
            $table->json('payload')->nullable()->comment('结构化载荷（变更明细等）');
            $table->timestamp('read_at')->nullable()->comment('已读时间');
            $table->timestamp('webhook_delivered_at')->nullable()->comment('出站 webhook 送达时间');
            $table->text('webhook_last_error')->nullable()->comment('最近一次 webhook 错误');
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'created_at'], 'repo_watch_notifications_user_unread_idx');
            $table->index(['user_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_watch_notifications');
    }
};
