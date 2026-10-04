<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階4（4a）の列（段階4 設計書 §5.3）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-10-03-approval-phase4a.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること**（Phase4aTablesTest が見る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_members', function (Blueprint $table) {
            $table->string('stamp_text', 4)->nullable()->after('is_admin')
                  ->comment('印に使う文字（空なら氏名の最初の空白より前）');
        });

        Schema::table('approval_steps', function (Blueprint $table) {
            $table->string('stamp_label', 6)->nullable()->after('comment')
                  ->comment('押したときの印の上段（部門の略称か「社長」）');
            $table->string('stamp_text', 100)->nullable()->after('stamp_label')
                  ->comment('押したときの印の下段（印に使う文字）');
        });
    }

    public function down(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            $table->dropColumn(['stamp_label', 'stamp_text']);
        });

        Schema::table('approval_members', function (Blueprint $table) {
            $table->dropColumn('stamp_text');
        });
    }
};
