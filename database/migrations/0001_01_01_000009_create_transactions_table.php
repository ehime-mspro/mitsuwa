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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('department', ['tenant', 'mansion', 'housing', 'realestate', 'welfare', 'dad']);
            $table->enum('transaction_type', ['sales', 'income', 'expense']);
            $table->date('transaction_date')->comment('取引発生日');
            $table->string('accounting_ym', 7)->comment('計上年月（YYYY-MM）');
            $table->string('category', 50)->comment('費目');
            $table->integer('amount_excl_tax')->comment('金額（税抜・円）');
            $table->integer('tax_amount')->nullable()->default(0)->comment('消費税額（円）');
            $table->integer('amount_incl_tax')->comment('金額（税込・円）');
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('summary', 500)->nullable()->comment('摘要');
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // インデックス
            $table->index(['department', 'accounting_ym'], 'idx_tx_department_ym');
            $table->index('property_id', 'idx_tx_property');
            $table->index('customer_id', 'idx_tx_customer');
            $table->index('contract_id', 'idx_tx_contract');
            $table->index('transaction_date', 'idx_tx_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
