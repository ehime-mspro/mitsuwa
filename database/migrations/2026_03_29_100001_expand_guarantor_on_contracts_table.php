<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 保証人情報を2名×4フィールドに拡張
     */
    public function up(): void
    {
        // 1. 新カラム追加（8本）
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('guarantor1_name', 100)->nullable()->after('guarantor_contact');
            $table->string('guarantor1_address', 500)->nullable()->after('guarantor1_name');
            $table->string('guarantor1_contact', 100)->nullable()->after('guarantor1_address');
            $table->string('guarantor1_workplace', 200)->nullable()->after('guarantor1_contact');
            $table->string('guarantor2_name', 100)->nullable()->after('guarantor1_workplace');
            $table->string('guarantor2_address', 500)->nullable()->after('guarantor2_name');
            $table->string('guarantor2_contact', 100)->nullable()->after('guarantor2_address');
            $table->string('guarantor2_workplace', 200)->nullable()->after('guarantor2_contact');
        });

        // 2. 既存データ移行（旧 → 新）
        DB::statement('
            UPDATE contracts
            SET guarantor1_name = guarantor_name,
                guarantor1_contact = guarantor_contact
            WHERE guarantor_name IS NOT NULL OR guarantor_contact IS NOT NULL
        ');

        // 3. 旧カラム削除
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['guarantor_name', 'guarantor_contact']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('guarantor_name', 100)->nullable()->after('notes');
            $table->string('guarantor_contact', 500)->nullable()->after('guarantor_name');
        });

        DB::statement('
            UPDATE contracts
            SET guarantor_name = guarantor1_name,
                guarantor_contact = guarantor1_contact
            WHERE guarantor1_name IS NOT NULL OR guarantor1_contact IS NOT NULL
        ');

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'guarantor1_name', 'guarantor1_address', 'guarantor1_contact', 'guarantor1_workplace',
                'guarantor2_name', 'guarantor2_address', 'guarantor2_contact', 'guarantor2_workplace',
            ]);
        });
    }
};
