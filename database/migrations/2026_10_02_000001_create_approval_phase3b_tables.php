<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階3（3b）の表（段階3 設計書 §5.3）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-10-02-approval-phase3b.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること**（Phase3bTablesTest が見る）。
 * ⚠ 索引名は本番の SQL と同じ名前を渡す（段階1・2・3a と同じ流儀）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('start_date')->comment('開始日');
            $table->date('end_date')->comment('終了日（開始日と同じか後）');
            $table->boolean('repeats_yearly')->default(false)->comment('毎年繰り返すか（1 なら月と日だけで比べる）');
            $table->string('description', 50)->comment('説明（例: 年末年始）');
            $table->timestamps();
        });

        Schema::create('approval_reminder_runs', function (Blueprint $table) {
            $table->id();
            $table->date('sent_on')->comment('催促を送った日（日本の日付）');
            $table->unsignedInteger('recipient_count')->comment('催促のメールを送った人数');
            $table->unsignedInteger('item_count')->comment('催促した申請の件数（のべ。1 通に載せきれなかった分も数える）');
            $table->timestamp('created_at')->nullable();

            $table->unique('sent_on', 'uq_approval_reminder_runs_sent_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_reminder_runs');
        Schema::dropIfExists('approval_holidays');
    }
};
