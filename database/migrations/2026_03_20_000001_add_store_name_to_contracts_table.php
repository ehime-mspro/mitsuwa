<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * STEP 4: contractsテーブルにstore_nameカラムを追加
     * フロアマップで店舗名を表示するために使用
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('store_name', 200)->nullable()->after('pest_control_fee')
                  ->comment('店舗名（フロアマップ表示用）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('store_name');
        });
    }
};
