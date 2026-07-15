<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watched_packages', function (Blueprint $table) {
            $table->id()->comment('记录 ID');
            $table->unsignedBigInteger('user_id')->comment('所属用户 ID');
            $table->string('source_provider', 32)->default('github')->comment('代码托管平台');
            $table->string('source_owner', 128)->comment('仓库所有者');
            $table->string('source_repo', 128)->comment('仓库名');
            $table->string('source_url', 500)->comment('仓库 URL');
            $table->string('ecosystem')->comment('包生态（npm/composer/pypi）');
            $table->string('package_name', 128)->comment('包名（如 lodash）');
            $table->string('manifest_path')->nullable()->comment('包清单文件路径');
            $table->string('current_version_constraint')->nullable()->comment('当前版本约束（如 ^1.0.0）');
            $table->string('normalized_current_version')->nullable()->comment('规范化的当前版本基线（如 1.0.0）');
            $table->string('latest_version')->nullable()->comment('注册中心最新版本');
            $table->string('watch_level')->default('minor')->comment('监控级别：patch/minor/major');
            $table->string('latest_update_type')->nullable()->comment('最新更新类型（patch/minor/major）');
            $table->string('registry_url', 500)->nullable()->comment('包注册中心页面 URL');
            $table->timestamp('last_checked_at')->nullable()->comment('最后检查时间');
            $table->text('last_error')->nullable()->comment('最后一次检查错误信息');
            $table->json('metadata')->nullable()->comment('额外元数据（JSON）');
            $table->timestamps();

            $table->index('user_id');
            $table->unique(
                ['user_id', 'source_provider', 'source_owner', 'source_repo', 'ecosystem', 'package_name'],
                'watched_packages_unique'
            );
            $table->index(['user_id', 'latest_update_type']);
        });
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE watched_packages COMMENT = '监控依赖包表'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('watched_packages');
    }
};
