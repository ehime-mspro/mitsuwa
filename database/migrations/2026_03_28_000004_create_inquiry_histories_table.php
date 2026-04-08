<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->cascadeOnDelete();
            $table->string('action_type', 50)->comment('対応種別');
            $table->date('action_date')->comment('対応日');
            $table->text('content')->comment('対応内容');
            $table->foreignId('created_by')->constrained('users')->comment('記録者');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_histories');
    }
};
