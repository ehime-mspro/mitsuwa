<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->integer('first_month_recovery')->nullable()->after('deposit_refund_date')->comment('初月回収額（円）');
            $table->integer('last_month_recovery')->nullable()->after('first_month_recovery')->comment('最終月回収額（円）');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['first_month_recovery', 'last_month_recovery']);
        });
    }
};
