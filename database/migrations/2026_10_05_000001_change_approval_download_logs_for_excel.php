<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階4（4b）の出力の記録（段階4 設計書 §5.3・§5.7・D25）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-10-05-approval-phase4b.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること**（Phase4bTablesTest が見る）。
 * ⚠ SQLite の `change()` は表を作り直す（Laravel 12）。外部キーと索引は残る（Phase4bTablesTest が見る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_download_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id')->nullable()->comment('添付・PDF の申請（Excel は空）')->change();
            $table->json('filters')->nullable()->after('user_agent')->comment('Excel の絞り込みの条件');
            $table->unsignedInteger('request_count')->nullable()->after('filters')->comment('Excel に出した件数');
        });
    }

    public function down(): void
    {
        Schema::table('approval_download_logs', function (Blueprint $table) {
            $table->dropColumn(['filters', 'request_count']);
        });

        Schema::table('approval_download_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id')->nullable(false)->comment('')->change();
        });
    }
};
