<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rent_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->restrictOnDelete();
            $table->date('revision_date')->comment('改定適用日');
            $table->integer('old_rent')->comment('旧家賃（円）');
            $table->integer('new_rent')->comment('新家賃（円）');
            $table->integer('old_common_fee')->nullable()->comment('旧共益費（円）');
            $table->integer('new_common_fee')->nullable()->comment('新共益費（円）');
            $table->integer('old_deposit')->nullable()->comment('旧敷金（円）');
            $table->integer('new_deposit')->nullable()->comment('新敷金（円）');
            $table->text('reason')->nullable()->comment('改定理由');
            $table->foreignId('revised_by')->constrained('users')->restrictOnDelete()->comment('改定実行者（経営層）');
            $table->timestamp('created_at')->useCurrent();

            // インデックス
            $table->index('contract_id', 'idx_revisions_contract');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rent_revisions');
    }
};
