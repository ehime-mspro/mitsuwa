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
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number', 30)->unique();
            $table->enum('department', ['tenant', 'mansion', 'housing', 'realestate', 'welfare', 'dad']);
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->enum('status', ['active', 'terminated'])->default('active');
            $table->date('contract_date')->comment('契約締結日');
            $table->date('rent_start_date')->comment('家賃発生日');
            $table->date('contract_end_date')->nullable()->comment('契約終了日');
            $table->text('termination_reason')->nullable()->comment('退去理由');

            // 契約条件（費用5項目）
            $table->integer('rent')->comment('契約家賃（円）');
            $table->integer('common_fee')->nullable()->default(0)->comment('契約共益費（円）');
            $table->integer('deposit')->nullable()->default(0)->comment('契約敷金（円）');
            $table->integer('garbage_fee')->nullable()->default(0)->comment('契約ゴミ代（円）');
            $table->integer('pest_control_fee')->nullable()->default(0)->comment('契約駆除代（円）');

            // 敷金返還情報（解約時に記録）
            $table->integer('deposit_deduction')->nullable()->comment('敷金控除額（円）');
            $table->text('deposit_deduction_reason')->nullable()->comment('控除理由');
            $table->integer('deposit_refund_amount')->nullable()->comment('敷金返還額（円）');
            $table->date('deposit_refund_date')->nullable()->comment('返還予定日');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // インデックス
            $table->index('property_id', 'idx_contracts_property');
            $table->index('unit_id', 'idx_contracts_unit');
            $table->index('customer_id', 'idx_contracts_customer');
            $table->index('status', 'idx_contracts_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
