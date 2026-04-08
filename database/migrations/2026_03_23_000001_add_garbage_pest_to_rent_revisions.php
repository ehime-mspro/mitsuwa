<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * STEP 6: rent_revisionsテーブルにゴミ代・駆除代カラムを追加
     * 賃料改定で家賃・共益費に加えてゴミ代・駆除代も改定対象にする
     */
    public function up(): void
    {
        Schema::table('rent_revisions', function (Blueprint $table) {
            $table->integer('old_garbage_fee')->nullable()->after('new_common_fee')
                  ->comment('旧ゴミ代（円）');
            $table->integer('new_garbage_fee')->nullable()->after('old_garbage_fee')
                  ->comment('新ゴミ代（円）');
            $table->integer('old_pest_control_fee')->nullable()->after('new_garbage_fee')
                  ->comment('旧駆除代（円）');
            $table->integer('new_pest_control_fee')->nullable()->after('old_pest_control_fee')
                  ->comment('新駆除代（円）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rent_revisions', function (Blueprint $table) {
            $table->dropColumn([
                'old_garbage_fee',
                'new_garbage_fee',
                'old_pest_control_fee',
                'new_pest_control_fee',
            ]);
        });
    }
};
