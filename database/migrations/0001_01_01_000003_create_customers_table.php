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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 200);
            $table->string('name_kana', 200)->nullable();
            $table->enum('customer_type', ['corporation', 'sole_proprietor', 'individual']);
            $table->string('representative', 100)->nullable()->comment('代表者名');
            $table->string('contact_person', 100)->nullable()->comment('担当者名');
            $table->string('postal_code', 10)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('guarantor_name', 100)->nullable()->comment('保証人名');
            $table->string('guarantor_contact', 500)->nullable()->comment('保証人連絡先');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // インデックス
            $table->index('name_kana', 'idx_customers_name_kana');
            $table->index('customer_type', 'idx_customers_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
