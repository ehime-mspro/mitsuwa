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
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('inquiry_number', 30)->unique();
            $table->string('contact_name', 100)->comment('問合せ者名');
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_company', 200)->nullable()->comment('会社名・所属');
            $table->enum('status', ['active', 'converted', 'lost', 'on_hold'])->default('active');
            $table->foreignId('initial_property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('initial_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('source', 50)->nullable()->comment('問合せ経路');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('first_contact_date')->comment('初回問合せ日');

            // 成約連携
            $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('converted_contract_id')->nullable()->constrained('contracts')->nullOnDelete();

            $table->text('notes')->nullable()->comment('全体メモ');
            $table->timestamps();
            $table->softDeletes();

            // インデックス
            $table->index('status', 'idx_inquiry_status');
            $table->index('initial_property_id', 'idx_inquiry_property');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
