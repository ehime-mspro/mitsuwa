<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_usage_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('用途名');
            $table->integer('sort_order')->default(0)->comment('表示順');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_usage_types');
    }
};
