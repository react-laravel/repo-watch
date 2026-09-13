<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_advisories', function (Blueprint $table) {
            $table->id()->comment('记录 ID');
            $table->string('source', 32)->default('osv')->comment('数据源（osv）');
            $table->string('advisory_id', 128)->comment('公告 ID（如 GHSA-… / OSV-…）');
            $table->string('ecosystem', 32)->comment('包生态（npm/composer）');
            $table->string('package_name', 200)->comment('包名');
            $table->string('severity', 32)->default('unknown')->comment('严重级别：critical/high/moderate/low/unknown');
            $table->string('summary', 500)->nullable()->comment('摘要');
            $table->json('aliases')->nullable()->comment('别名（CVE 等）');
            $table->json('affected_ranges')->nullable()->comment('原始影响范围（调试用）');
            $table->string('fixed_version', 128)->nullable()->comment('已知修复版本（若有）');
            $table->string('reference_url', 500)->nullable()->comment('参考链接');
            $table->timestamp('published_at')->nullable()->comment('公告发布时间');
            $table->timestamp('withdrawn_at')->nullable()->comment('撤回时间');
            $table->timestamp('last_fetched_at')->nullable()->comment('最近拉取时间');
            $table->timestamps();

            $table->unique(
                ['source', 'advisory_id', 'ecosystem', 'package_name'],
                'package_advisories_source_id_pkg_unique'
            );
            $table->index(['ecosystem', 'package_name']);
            $table->index('severity');
        });

        Schema::create('package_advisory_findings', function (Blueprint $table) {
            $table->id()->comment('记录 ID');
            $table->foreignId('watched_repository_id')
                ->constrained('watched_repositories')
                ->cascadeOnDelete();
            $table->foreignId('package_advisory_id')
                ->constrained('package_advisories')
                ->cascadeOnDelete();
            $table->foreignId('dependency_snapshot_id')
                ->nullable()
                ->constrained('dependency_snapshots')
                ->nullOnDelete();
            $table->string('ecosystem', 32)->comment('包生态');
            $table->string('manifest_path', 255)->comment('清单路径');
            $table->string('package_name', 200)->comment('包名');
            $table->string('installed_version', 128)->comment('命中时的安装版本');
            $table->string('status', 32)->default('open')->comment('状态：open/resolved');
            $table->timestamp('first_detected_at')->comment('首次发现时间');
            $table->timestamp('last_seen_at')->comment('最近仍命中时间');
            $table->timestamp('resolved_at')->nullable()->comment('已解决时间');
            $table->timestamps();

            $table->unique(
                ['watched_repository_id', 'package_advisory_id', 'manifest_path'],
                'package_advisory_findings_repo_adv_manifest_unique'
            );
            $table->index(['watched_repository_id', 'status', 'last_seen_at']);
            $table->index(['ecosystem', 'package_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_advisory_findings');
        Schema::dropIfExists('package_advisories');
    }
};
