<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 募集家賃の賃料改定履歴テーブル。
     * rent_revisions（契約専用）のミラーで、contract_id の代わりに unit_id を持つ。
     * 既存テーブルには一切手を入れない（追加のみ）。
     */
    public function up(): void
    {
        Schema::create('unit_rent_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->date('revision_date')->comment('改定適用日');
            $table->integer('old_rent')->comment('旧募集家賃（円）');
            $table->integer('new_rent')->comment('新募集家賃（円）');
            $table->integer('old_common_fee')->nullable()->comment('旧共益費（円）');
            $table->integer('new_common_fee')->nullable()->comment('新共益費（円）');
            $table->integer('old_garbage_fee')->nullable()->comment('旧ゴミ代（円）');
            $table->integer('new_garbage_fee')->nullable()->comment('新ゴミ代（円）');
            $table->integer('old_pest_control_fee')->nullable()->comment('旧駆除代（円）');
            $table->integer('new_pest_control_fee')->nullable()->comment('新駆除代（円）');
            $table->text('reason')->nullable()->comment('改定理由');
            $table->foreignId('revised_by')->constrained('users')->restrictOnDelete()->comment('改定実行者（経営層）');
            $table->timestamp('created_at')->useCurrent();

            $table->index('unit_id', 'idx_unit_revisions_unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_rent_revisions');
    }
};
