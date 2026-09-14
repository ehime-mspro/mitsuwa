<?php

use App\Enums\InitialMonthType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 契約（contracts）の初月・最終月の列を足す（SQLite のテストのための鏡。本番は raw SQL で管理している）。
 *
 * 本番の contracts にはこの 4 列があり、Contract の $fillable・契約の登録・CSV 取込（契約・過去契約）が書くのに、
 * リポジトリには DDL が 1 本も無い（本番で直接作られたまま。survey_questions / hs_property_files と同じ状況）。
 * そのため契約の CSV 取込の確定がテストで一度も通せなかった（`no column named initial_month_type`）。
 *
 * 本番の列定義（2026-09-14 に読み取りで確認）に合わせてある:
 *   initial_month_type varchar(10) NOT NULL DEFAULT 'full' ／ initial_month_amount int NULL ／
 *   final_month_type varchar(10) NULL ／ final_month_amount int NULL。
 * ⚠ 初月の種類は NOT NULL。null を書くと本番では落ちるので、テストでも落ちるようにしてある（NULL 可にしない）。
 *   種類の値（InitialMonthType）は本番でも CHECK されていない（varchar）ので、ここでも enum にしない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('initial_month_type', 10)->default(InitialMonthType::Full->value)->comment('初月の賃料の扱い');
            $table->integer('initial_month_amount')->nullable()->comment('初月の金額（手動入力のとき）');
            $table->string('final_month_type', 10)->nullable()->comment('最終月の賃料の扱い');
            $table->integer('final_month_amount')->nullable()->comment('最終月の金額（手動入力のとき）');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['initial_month_type', 'initial_month_amount', 'final_month_type', 'final_month_amount']);
        });
    }
};
