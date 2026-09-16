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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // 決裁申請 段階1: 社員番号でもログインする（設計書 §5.6・D5）。live DB は raw SQL で別途追加
            $table->string('employee_number', 20)->nullable()->unique();
            // 決裁のみ利用者はメールアドレスを持たないことがある（設計書 §5.6）。社員番号との「どちらかは必須」は
            // アプリ側で担保する（DB の CHECK にすると MySQL / SQLite で書き方が割れる）
            $table->string('email', 255)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 255);
            // ⚠ 値を増やすときはこの行を直す。Schema::table(...)->enum(...)->change() は使わない
            //    （SQLite がテーブルを作り直し、status の CHECK が黙って消える。Bug #60 / UsersSchemaTest）
            $table->enum('role', ['executive', 'manager', 'staff', 'approval_only'])->default('staff');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('must_change_password')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes(); // 論理削除（担当者履歴を残すため。live DB は raw SQL で別途追加）

            // インデックス
            $table->index(['role', 'status'], 'idx_users_role_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
