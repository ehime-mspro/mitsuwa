<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階6（6a）の列（段階6 設計書 §5.3・§5.7）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-10-27-approval-phase6a.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること**（Phase6aColumnsTest が見る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_members', function (Blueprint $table) {
            $table->boolean('is_tester')->default(false)->after('stamp_text')
                  ->comment('試す人（使い始める前に本番で申請を回して確かめる人）');
        });

        Schema::table('approval_number_sequences', function (Blueprint $table) {
            $table->unsignedInteger('next_without_trials')->default(1)->after('last_issued')
                  ->comment('試しの採番だけを除いた次の番号');
        });

        // 今の行をそろえる（SQL の 3 と同じ）
        DB::table('approval_number_sequences')->update(['next_without_trials' => DB::raw('next_number')]);
    }

    public function down(): void
    {
        Schema::table('approval_number_sequences', function (Blueprint $table) {
            $table->dropColumn('next_without_trials');
        });

        Schema::table('approval_members', function (Blueprint $table) {
            $table->dropColumn('is_tester');
        });
    }
};
