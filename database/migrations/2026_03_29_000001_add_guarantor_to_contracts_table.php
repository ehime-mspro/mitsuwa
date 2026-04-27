<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('guarantor_name', 100)->nullable()->after('notes')->comment('保証人名');
            $table->string('guarantor_contact', 500)->nullable()->after('guarantor_name')->comment('保証人連絡先');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['guarantor_name', 'guarantor_contact']);
        });
    }
};
