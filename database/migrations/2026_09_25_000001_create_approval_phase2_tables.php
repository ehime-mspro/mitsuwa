<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階2（2a）の表（設計書 §5.3）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-09-25-approval-phase2a.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること。**
 *
 * ⚠ 状態などの値は enum にせず string（設計書 §5.3。SQLite で enum を変えると CHECK が消える。Bug #60）。
 * ⚠ 索引名は本番の SQL と同じ名前を渡す（本番に手で流すため。段階1 と同じ流儀）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_departments', function (Blueprint $table) {
            $table->foreignId('head_user_id')->nullable()->after('code')
                  ->constrained('users')->comment('部門長');
            $table->index('head_user_id', 'idx_approval_departments_head');
        });

        Schema::table('approval_settings', function (Blueprint $table) {
            $table->timestamp('launched_at')->nullable()->after('president_user_id')
                  ->comment('使い始めた日時（NULL のあいだは準備中）');
        });

        Schema::create('approval_reviewers', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained('approval_departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->primary(['department_id', 'user_id']);
            $table->index('user_id', 'idx_approval_reviewers_user');
        });

        Schema::create('approval_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->text('headings')->comment('5W2H の見出し（本文の初期値）');
            $table->foreignId('review_department_id')->constrained('approval_departments')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name', 'uq_approval_types_name');
            $table->index('review_department_id', 'idx_approval_types_review_dept');
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->comment('申請者');
            $table->foreignId('department_id')->nullable()->constrained('approval_departments')->restrictOnDelete();
            $table->foreignId('type_id')->nullable()->constrained('approval_types')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('decision', 20)->nullable()->comment('社長の判断');
            $table->string('subject', 100)->nullable();
            $table->unsignedBigInteger('amount')->nullable()->comment('円・税抜');
            $table->string('schedule', 50)->nullable()->comment('実施時期');
            $table->text('body')->nullable()->comment('重点ポイント（5W2H）');
            $table->json('related_numbers')->nullable();
            $table->unsignedSmallInteger('round')->default(0)->comment('提出の回数');
            $table->string('number', 20)->nullable()->comment('決裁No');
            $table->foreignId('number_department_id')->nullable()->constrained('approval_departments')->restrictOnDelete();
            $table->unsignedSmallInteger('number_fiscal_year')->nullable();
            $table->unsignedInteger('number_seq')->nullable();
            $table->timestamp('first_submitted_at')->nullable();
            $table->timestamp('last_submitted_at')->nullable()->comment('発信日');
            $table->timestamp('decided_at')->nullable()->comment('決裁日');
            $table->timestamp('finished_at')->nullable()->comment('決裁が完了した日時');
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0)->comment('同時操作の見張り');
            $table->timestamps();

            $table->unique('number', 'uq_approval_requests_number');
            $table->index(['user_id', 'status'], 'idx_approval_requests_user_status');
            $table->index(['department_id', 'status'], 'idx_approval_requests_dept_status');
            $table->index('type_id', 'idx_approval_requests_type');
            $table->index('status', 'idx_approval_requests_status');
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->string('kind', 20);
            $table->foreignId('department_id')->nullable()->constrained('approval_departments')->restrictOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users');
            $table->string('status', 20)->default('pending');
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->string('result', 20)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'round'], 'idx_approval_steps_request_round');
            $table->index(['kind', 'status'], 'idx_approval_steps_kind_status');
            $table->index('department_id', 'idx_approval_steps_dept');
            $table->index('assignee_user_id', 'idx_approval_steps_assignee');
            $table->index('actor_user_id', 'idx_approval_steps_actor');
        });

        Schema::create('approval_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->unsignedSmallInteger('round');
            $table->json('snapshot');
            $table->foreignId('submitted_by')->constrained('users');
            // ⚠ updated_at は持たない（追記のみ）
            $table->timestamp('created_at')->nullable();

            $table->unique(['request_id', 'round'], 'uq_approval_revisions_request_round');
        });

        Schema::create('approval_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->unsignedSmallInteger('round')->default(0);
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->string('action', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('step_id')->nullable()->constrained('approval_steps')->restrictOnDelete();
            $table->string('result', 20)->nullable();
            $table->text('comment')->nullable();
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['request_id', 'id'], 'idx_approval_histories_request');
            $table->index('actor_user_id', 'idx_approval_histories_actor');
        });

        Schema::create('approval_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('stored_path', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->unsignedSmallInteger('added_round');
            $table->unsignedSmallInteger('removed_round')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique('stored_path', 'uq_approval_attachments_path');
            $table->index('request_id', 'idx_approval_attachments_request');
        });

        Schema::create('approval_download_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained('approval_attachments')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('kind', 20);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('request_id', 'idx_approval_download_logs_request');
            $table->index('user_id', 'idx_approval_download_logs_user');
        });

        Schema::create('approval_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('approval_departments')->restrictOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedInteger('last_issued')->default(0);
            $table->timestamps();

            $table->unique(['department_id', 'fiscal_year'], 'uq_approval_number_sequences');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_number_sequences');
        Schema::dropIfExists('approval_download_logs');
        Schema::dropIfExists('approval_attachments');
        Schema::dropIfExists('approval_histories');
        Schema::dropIfExists('approval_revisions');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_types');
        Schema::dropIfExists('approval_reviewers');

        Schema::table('approval_settings', function (Blueprint $table) {
            $table->dropColumn('launched_at');
        });

        Schema::table('approval_departments', function (Blueprint $table) {
            $table->dropForeign(['head_user_id']);
            $table->dropIndex('idx_approval_departments_head');
            $table->dropColumn('head_user_id');
        });
    }
};
