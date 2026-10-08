<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階5（5a）の列と表（段階5 設計書 §5.3）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-10-07-approval-phase5a.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること**（Phase5aTablesTest が見る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_types', function (Blueprint $table) {
            $table->string('body_form', 20)->default('points')->after('headings')
                  ->comment('本文の形（points＝5W2H の見出し／table＝金額の明細表）');
            $table->json('table_layout')->nullable()->after('body_form')
                  ->comment('明細表の行（前半・後半の行の名前。名前が空なら自由行）と「計」の行の有無');
            $table->string('subject_suffix', 50)->nullable()->after('table_layout')->comment('件名の決まり文句');
            $table->boolean('uses_tsubo')->default(false)->after('subject_suffix')->comment('坪数を使う');
            $table->boolean('uses_tsubo_price')->default(false)->after('uses_tsubo')->comment('坪単価を使う');
            $table->boolean('uses_staff')->default(false)->after('uses_tsubo_price')->comment('担当者を使う（使う種類では提出に必須）');
            $table->boolean('uses_contract_date')->default(false)->after('uses_staff')->comment('契約予定日を使う（使う種類では提出に必須）');
            $table->text('fixed_text')->nullable()->after('uses_contract_date')->comment('定型文（本文の下に出す固定の文）');
        });

        Schema::create('approval_type_department', function (Blueprint $table) {
            $table->foreignId('type_id')->constrained('approval_types')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('approval_departments')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['type_id', 'department_id']);
            $table->index('department_id', 'idx_approval_type_department_dept');
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->json('amount_table')->nullable()->after('body')
                  ->comment('金額の明細表（前半・後半の行と「計」の行の有無。明細表の種類だけ）');
            $table->decimal('tsubo', 7, 2)->nullable()->after('amount_table')->comment('坪数');
            $table->unsignedBigInteger('tsubo_price')->nullable()->after('tsubo')->comment('坪単価（円）');
            $table->string('staff', 50)->nullable()->after('tsubo_price')->comment('担当者');
            $table->date('contract_date')->nullable()->after('staff')->comment('契約予定日');
            $table->text('fixed_text')->nullable()->after('contract_date')->comment('定型文（保存したときに種類から写す。提出したあとは変わらない）');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn(['amount_table', 'tsubo', 'tsubo_price', 'staff', 'contract_date', 'fixed_text']);
        });

        Schema::dropIfExists('approval_type_department');

        Schema::table('approval_types', function (Blueprint $table) {
            $table->dropColumn([
                'body_form', 'table_layout', 'subject_suffix',
                'uses_tsubo', 'uses_tsubo_price', 'uses_staff', 'uses_contract_date', 'fixed_text',
            ]);
        });
    }
};
