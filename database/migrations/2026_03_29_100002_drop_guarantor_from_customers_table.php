<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 保証人情報は契約に移動済みのため、customers テーブルから削除
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['guarantor_name', 'guarantor_contact']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('guarantor_name', 100)->nullable()->after('email');
            $table->string('guarantor_contact', 500)->nullable()->after('guarantor_name');
        });
    }
};
