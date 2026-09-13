<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watched_repositories', function (Blueprint $table) {
            $table->id()->comment('记录 ID');
            $table->unsignedBigInteger('user_id')->comment('所属用户 ID');
            $table->string('provider', 32)->default('github')->comment('代码托管平台');
            $table->string('owner', 128)->comment('仓库所有者');
            $table->string('repo', 128)->comment('仓库名');
            $table->string('url', 500)->comment('仓库 URL');
            $table->string('full_name', 260)->nullable()->comment('owner/repo 显示名');
            $table->text('description')->nullable()->comment('仓库描述');
            $table->string('default_branch', 128)->nullable()->comment('默认分支');
            $table->string('scan_status', 32)->default('idle')->comment('扫描状态：idle/pending/scanning/error');
            $table->timestamp('last_scanned_at')->nullable()->comment('上次依赖快照扫描时间');
            $table->timestamp('next_scan_at')->nullable()->comment('下次计划扫描时间');
            $table->text('last_scan_error')->nullable()->comment('上次扫描错误');
            $table->unsignedInteger('package_count')->default(0)->comment('当前快照依赖包数量');
            $table->json('metadata')->nullable()->comment('额外元数据（JSON）');
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'owner', 'repo'], 'watched_repositories_unique');
            $table->index(['next_scan_at', 'scan_status']);
            $table->index('user_id');
        });

        Schema::create('dependency_snapshots', function (Blueprint $table) {
            $table->id()->comment('记录 ID');
            $table->foreignId('watched_repository_id')
                ->constrained('watched_repositories')
                ->cascadeOnDelete();
            $table->string('ecosystem', 32)->comment('包生态（npm/composer）');
            $table->string('manifest_path', 255)->comment('清单文件路径');
            $table->string('packages_hash', 64)->comment('依赖内容哈希，用于快速比对');
            $table->json('packages')->comment('依赖快照内容');
            $table->unsignedInteger('package_count')->default(0)->comment('依赖包数量');
            $table->timestamp('scanned_at')->comment('扫描时间');
            $table->timestamps();

            $table->index(['watched_repository_id', 'scanned_at'], 'dependency_snapshots_repo_scanned_idx');
            $table->index(['watched_repository_id', 'ecosystem', 'manifest_path'], 'dependency_snapshots_manifest_idx');
        });

        Schema::create('dependency_changes', function (Blueprint $table) {
            $table->id()->comment('记录 ID');
            $table->foreignId('watched_repository_id')
                ->constrained('watched_repositories')
                ->cascadeOnDelete();
            $table->foreignId('dependency_snapshot_id')
                ->nullable()
                ->constrained('dependency_snapshots')
                ->nullOnDelete();
            $table->string('ecosystem', 32)->comment('包生态');
            $table->string('manifest_path', 255)->comment('清单文件路径');
            $table->string('package_name', 200)->comment('包名');
            $table->string('change_type', 32)->comment('变更类型：added/removed/updated');
            $table->string('previous_constraint')->nullable()->comment('变更前版本约束');
            $table->string('new_constraint')->nullable()->comment('变更后版本约束');
            $table->string('previous_version')->nullable()->comment('变更前规范化版本');
            $table->string('new_version')->nullable()->comment('变更后规范化版本');
            $table->timestamp('detected_at')->comment('检测时间');
            $table->timestamps();

            $table->index(['watched_repository_id', 'detected_at'], 'dependency_changes_repo_detected_idx');
            $table->index('detected_at');
            $table->index(['ecosystem', 'package_name']);
        });

        Schema::table('watched_packages', function (Blueprint $table) {
            $table->foreignId('watched_repository_id')
                ->nullable()
                ->after('registry_package_id')
                ->constrained('watched_repositories')
                ->nullOnDelete();
        });

        $this->backfillWatchedRepositories();
    }

    public function down(): void
    {
        Schema::table('watched_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('watched_repository_id');
        });

        Schema::dropIfExists('dependency_changes');
        Schema::dropIfExists('dependency_snapshots');
        Schema::dropIfExists('watched_repositories');
    }

    private function backfillWatchedRepositories(): void
    {
        $now = now();

        $groups = DB::table('watched_packages')
            ->select([
                'user_id',
                'source_provider',
                'source_owner',
                'source_repo',
                'source_url',
                DB::raw('MIN(id) as first_id'),
                DB::raw('COUNT(*) as package_count'),
            ])
            ->groupBy('user_id', 'source_provider', 'source_owner', 'source_repo', 'source_url')
            ->orderBy('first_id')
            ->get();

        foreach ($groups as $group) {
            $owner = strtolower((string) $group->source_owner);
            $repo = strtolower((string) $group->source_repo);
            $provider = (string) $group->source_provider;

            $repositoryId = DB::table('watched_repositories')->insertGetId([
                'user_id' => $group->user_id,
                'provider' => $provider,
                'owner' => $owner,
                'repo' => $repo,
                'url' => $group->source_url,
                'full_name' => "{$owner}/{$repo}",
                'description' => null,
                'default_branch' => null,
                'scan_status' => 'idle',
                'last_scanned_at' => null,
                'next_scan_at' => $now,
                'last_scan_error' => null,
                'package_count' => (int) $group->package_count,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('watched_packages')
                ->where('user_id', $group->user_id)
                ->where('source_provider', $provider)
                ->whereRaw('LOWER(source_owner) = ?', [$owner])
                ->whereRaw('LOWER(source_repo) = ?', [$repo])
                ->update(['watched_repository_id' => $repositoryId]);
        }
    }
};
