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
        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->string('investment_number', 30)->unique();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->enum('pattern', ['renovation', 'new_build', 'demolish_rebuild'])->comment('投資パターン');
            $table->enum('status', ['planning', 'in_progress', 'recovering', 'recovered'])->default('planning');
            $table->text('description')->comment('工事概要');
            $table->date('start_date')->nullable()->comment('工事開始日');
            $table->date('end_date')->nullable()->comment('工事完了日');
            $table->integer('total_amount')->default(0)->comment('投資総額（円）');

            // 契約紐づけ・回収計算
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->integer('monthly_rent')->nullable()->comment('月額家賃（回収計算用）');
            $table->date('recovery_start_date')->nullable()->comment('回収開始日');
            $table->integer('estimated_recovery_months')->nullable()->comment('回収予定月数');
            $table->date('estimated_recovery_date')->nullable()->comment('回収予定日');
            $table->integer('total_recovered')->nullable()->default(0)->comment('累計回収額（円）');
            $table->decimal('recovery_rate', 5, 2)->nullable()->default(0.00)->comment('回収率（%）');

            $table->string('contractor_name', 200)->nullable()->comment('施工業者名');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // インデックス
            $table->index('property_id', 'idx_invest_property');
            $table->index('status', 'idx_invest_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investments');
    }
};
