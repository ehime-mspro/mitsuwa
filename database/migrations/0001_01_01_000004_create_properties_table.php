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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 200);
            $table->enum('property_type', ['tenant', 'mansion', 'land', 'building', 'facility', 'other']);
            $table->enum('department', ['tenant', 'mansion', 'housing', 'realestate', 'welfare', 'dad']);
            $table->enum('operation_status', ['active', 'inactive'])->default('active')->comment('稼働/非稼働');
            $table->string('postal_code', 10)->nullable();
            $table->string('address', 500);
            $table->string('structure', 50)->nullable()->comment('RC造・S造・木造等');
            $table->string('built_date', 7)->nullable()->comment('築年月（YYYY-MM）');
            $table->integer('total_floors')->nullable()->comment('総階数');
            $table->integer('total_units')->nullable()->comment('総区画数');
            $table->decimal('total_area', 10, 2)->nullable()->comment('延床面積（㎡）');
            $table->enum('owner_type', ['self_owned', 'owner'])->nullable()->comment('所有者区分');
            $table->string('owner_name', 200)->nullable()->comment('所有者名');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // インデックス
            $table->index(['department', 'operation_status'], 'idx_properties_dept_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
