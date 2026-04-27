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
        Schema::create('investment_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_id')->constrained('investments')->cascadeOnDelete();
            $table->string('cost_item', 100)->comment('費用項目名');
            $table->string('contractor_name', 200)->nullable()->comment('業者名');
            $table->integer('amount')->comment('金額（円）');
            $table->date('executed_at')->nullable()->comment('実施日');
            $table->text('notes')->nullable();
            $table->timestamps();

            // インデックス
            $table->index('investment_id', 'idx_invdetails_investment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investment_details');
    }
};
