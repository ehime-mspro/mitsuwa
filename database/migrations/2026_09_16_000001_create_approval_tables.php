<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階1 の表（設計書 §5.16）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-09-16-approval-phase1.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること。**
 *
 * ⚠ 基幹の `departments` / `department_user` とは別の表（D1）。
 * ⚠ 変更前後の列は `before` / `after` にしない（MySQL の予約語 BEFORE と紛らわしい）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedTinyInteger('fiscal_start_month')->default(1)->comment('期の始まりの月（1〜12）');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('approval_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('approval_companies')->restrictOnDelete();
            $table->string('name', 50);
            $table->string('short_name', 6)->comment('データ印の上段に入るので 6 文字まで');
            $table->string('code', 3)->unique()->comment('英大文字 1〜3 文字。申請番号に使う');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'uq_approval_departments_company_name');
        });

        Schema::create('approval_department_user', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained('approval_departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['department_id', 'user_id']);
            $table->index('user_id', 'idx_approval_dept_user_user');
        });

        Schema::create('approval_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('can_view_all')->default(false)->comment('全件閲覧者（段階2 で使う）');
            $table->boolean('is_admin')->default(false)->comment('決裁の管理者');
            $table->timestamps();
        });

        Schema::create('approval_settings', function (Blueprint $table) {
            $table->id();
            // ⚠ 利用者は SoftDelete なので、削除で外部キーが壊れることはない
            $table->foreignId('president_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('approval_mail_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 255)->unique();
            $table->timestamps();
        });

        Schema::create('approval_setting_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->string('action', 50);
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            // ⚠ updated_at は持たない（追記のみ。SettingLogTest が固定している）
            $table->timestamp('created_at')->nullable();

            $table->index(['target_type', 'target_id'], 'idx_approval_logs_target');
            $table->index('actor_user_id', 'idx_approval_logs_actor');
            $table->index('created_at', 'idx_approval_logs_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_setting_logs');
        Schema::dropIfExists('approval_mail_domains');
        Schema::dropIfExists('approval_settings');
        Schema::dropIfExists('approval_members');
        Schema::dropIfExists('approval_department_user');
        Schema::dropIfExists('approval_departments');
        Schema::dropIfExists('approval_companies');
    }
};
