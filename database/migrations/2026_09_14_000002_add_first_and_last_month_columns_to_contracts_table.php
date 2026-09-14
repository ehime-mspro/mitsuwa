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
 * ⚠ 本番の列定義（型・NULL 可否・既定値）は未確認。モデルの扱い（種類は InitialMonthType、読むときは null を「1ヶ月分」とみなす・
 *   金額は null なら月額合計）に合わせ、NULL 可・既定値なしにしてある。本番と突き合わせたらここを直すこと。
 */
return new class extends Migration
{
    public function up(): void
    {
        $types = array_column(InitialMonthType::cases(), 'value');

        Schema::table('contracts', function (Blueprint $table) use ($types) {
            $table->enum('initial_month_type', $types)->nullable()->comment('初月の賃料の扱い');
            $table->integer('initial_month_amount')->nullable()->comment('初月の金額（手動入力のとき）');
            $table->enum('final_month_type', $types)->nullable()->comment('最終月の賃料の扱い');
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
