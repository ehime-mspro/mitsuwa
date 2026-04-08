<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('inquiry_number', 20)->unique()->comment('問合せ番号 INQ-YYYY-NNN');
            $table->foreignId('property_id')->constrained()->comment('物件');
            $table->string('status', 20)->default('follow')->comment('ステータス');
            $table->string('contact_name', 200)->comment('問合せ者名');
            $table->string('company_name', 200)->nullable()->comment('会社名・屋号');
            $table->string('phone', 50)->nullable()->comment('電話番号');
            $table->string('email', 200)->nullable()->comment('メールアドレス');
            $table->date('inquiry_date')->comment('問合せ日');
            $table->string('source', 50)->nullable()->comment('問合せ経路');
            $table->foreignId('desired_usage_id')->nullable()->constrained('inquiry_usage_types')->nullOnDelete()->comment('希望用途');
            $table->decimal('desired_area_min', 8, 2)->nullable()->comment('希望面積下限（坪）');
            $table->decimal('desired_area_max', 8, 2)->nullable()->comment('希望面積上限（坪）');
            $table->integer('budget_max')->nullable()->comment('予算上限（万円）');
            $table->string('desired_move_date', 7)->nullable()->comment('希望入居月 YYYY-MM');
            $table->text('description')->nullable()->comment('問合せ内容・要望');
            $table->text('result_reason')->nullable()->comment('結果理由');
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete()->comment('関連契約');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete()->comment('担当者');
            $table->text('notes')->nullable()->comment('備考');
            $table->timestamps();
            $table->softDeletes();

            $table->index('property_id');
            $table->index('status');
            $table->index('inquiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
