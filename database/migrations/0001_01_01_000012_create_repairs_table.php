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
        Schema::create('repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->enum('status', ['planned', 'in_progress', 'completed'])->default('planned');
            $table->string('category', 50)->nullable()->comment('修繕カテゴリ');
            $table->text('description')->comment('修繕内容');
            $table->string('contractor_name', 200)->nullable()->comment('業者名');
            $table->date('started_at')->nullable()->comment('実施日');
            $table->date('completed_at')->nullable()->comment('完了日');
            $table->integer('cost')->nullable()->comment('費用（円）');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // インデックス
            $table->index('property_id', 'idx_repairs_property');
            $table->index('unit_id', 'idx_repairs_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repairs');
    }
};
