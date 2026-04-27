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
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->integer('floor')->nullable()->comment('階数（平屋型はNULL）');
            $table->string('room_number', 20)->comment('号室名（例: A, B, C）');
            $table->string('display_name', 50)->comment('表示名（「3A」形式、自動生成）');
            $table->decimal('area_tsubo', 8, 2)->nullable()->comment('面積（坪）');
            $table->enum('usage_type', ['shop', 'warehouse', 'office', 'other'])->nullable()->comment('用途');
            $table->enum('status', ['occupied', 'vacant', 'negotiating'])->default('vacant');
            $table->integer('rent')->nullable()->default(0)->comment('募集家賃（円）');
            $table->integer('common_fee')->nullable()->default(0)->comment('募集共益費（円）');
            $table->integer('deposit')->nullable()->default(0)->comment('募集敷金（円）');
            $table->integer('garbage_fee')->nullable()->default(0)->comment('募集ゴミ代（円）');
            $table->integer('pest_control_fee')->nullable()->default(0)->comment('募集駆除代（円）');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // UNIQUE制約: 同一物件内で表示名が重複しないこと
            $table->unique(['property_id', 'display_name'], 'idx_units_property_display');

            // インデックス
            $table->index('property_id', 'idx_units_property');
            $table->index('status', 'idx_units_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
