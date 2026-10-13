<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階5（5b）— 過去の紙の決裁と、その取り込み 1 回分（段階5 設計書 §5.3・§5.9〜§5.11・D9・D10）。
 *
 * ⚠ 本番は database/sql/2026-10-08-approval-phase5b.sql を手で流す。これはテスト（SQLite）のための鏡で、
 *   Phase5bTablesTest が両方を読んで同じ表であることを見る。
 * ⚠ 索引と外部キーの名前は SQL と同じ名前を書く（MySQL の SHOW CREATE TABLE が同じになる。5a の中間表は Laravel の既定の名前で、
 *   SQL と名前だけ違った＝5a 計画 §0.15 の 5）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_paper_imports', function (Blueprint $table) {
            $table->id();
            $table->string('file_name', 255)->comment('取り込んだファイルの名前');
            $table->unsignedSmallInteger('fiscal_year')->comment('年度（期の始まりの年）');
            $table->unsignedInteger('added_count')->default(0)->comment('足した記録の数');
            $table->unsignedInteger('filled_count')->default(0)->comment('金額を補った、すでにある記録の数');
            $table->unsignedBigInteger('imported_by');
            $table->timestamp('undone_at')->nullable()->comment('取り消した日時');
            $table->unsignedBigInteger('undone_by')->nullable();
            $table->unsignedInteger('undone_count')->nullable()->comment('取り消しで消した記録の数');
            $table->timestamps();

            $table->index('imported_by', 'idx_approval_paper_imports_imported_by');
            $table->index('undone_by', 'idx_approval_paper_imports_undone_by');
            $table->foreign('imported_by', 'fk_approval_paper_imports_imported_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('undone_by', 'fk_approval_paper_imports_undone_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('approval_paper_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->unsignedSmallInteger('fiscal_year')->comment('年度（期の始まりの年）');
            $table->unsignedInteger('seq')->comment('受付簿のＮＯ');
            $table->string('number', 20)->comment('決裁No（年度・部門・ＮＯから組み立てる。R8-J-021）');
            $table->date('decided_on')->comment('決裁日（受付簿の日付）');
            $table->string('applicant_name', 50)->nullable()->comment('申請者（受付簿の文字のまま。利用者と結び付けない）');
            $table->string('subject', 100)->comment('件名');
            $table->bigInteger('amount')->nullable()->comment('金額（金額の控えの契約金額）');
            $table->bigInteger('cost')->nullable()->comment('工事原価');
            $table->bigInteger('profit')->nullable()->comment('粗利益');
            $table->text('note')->nullable()->comment('備考');
            $table->unsignedBigInteger('import_id')->nullable()->comment('足した取り込み（手で登録したものは空）');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('手で直した人（取り込みで金額を補っても変えない）');
            $table->timestamps();

            $table->unique('number', 'uq_approval_paper_records_number');
            $table->unique(['department_id', 'fiscal_year', 'seq'], 'uq_approval_paper_records_seq');
            $table->index('decided_on', 'idx_approval_paper_records_decided');
            $table->index('import_id', 'idx_approval_paper_records_import');
            $table->index('created_by', 'idx_approval_paper_records_created_by');
            $table->index('updated_by', 'idx_approval_paper_records_updated_by');
            $table->foreign('department_id', 'fk_approval_paper_records_dept')->references('id')->on('approval_departments')->restrictOnDelete();
            $table->foreign('import_id', 'fk_approval_paper_records_import')->references('id')->on('approval_paper_imports')->restrictOnDelete();
            $table->foreign('created_by', 'fk_approval_paper_records_created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by', 'fk_approval_paper_records_updated_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_paper_records');
        Schema::dropIfExists('approval_paper_imports');
    }
};
