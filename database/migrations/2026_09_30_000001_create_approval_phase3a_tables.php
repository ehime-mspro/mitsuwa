<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階3（3a）の表（段階3 設計書 §5.3）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-09-30-approval-phase3a.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること**（Phase3TablesTest が見る）。
 * ⚠ `notifications` は Laravel 標準の形に、その申請の未読を索引で引くための `approval_request_id` を足したもの（3a 計画 §0.2）。
 * ⚠ 索引名は本番の SQL と同じ名前を渡す（段階1・2 と同じ流儀）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_settings', function (Blueprint $table) {
            $table->timestamp('mail_last_sent_at')->nullable()->after('launched_at')
                  ->comment('決裁のメールが最後に送れた日時');
            $table->timestamp('mail_last_failed_at')->nullable()->after('mail_last_sent_at')
                  ->comment('決裁のメールが最後に送れなかった日時（送り直し 3 回のあと）');
            $table->string('mail_last_failed_to', 255)->nullable()->after('mail_last_failed_at')
                  ->comment('最後に送れなかったメールの宛先（氏名）');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->foreignId('approval_request_id')->nullable()->comment('決裁の申請（その申請の未読を既読にするため）')
                  ->constrained('approval_requests')->restrictOnDelete();
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'idx_notifications_notifiable');
            $table->index('approval_request_id', 'idx_notifications_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('approval_settings', function (Blueprint $table) {
            $table->dropColumn(['mail_last_sent_at', 'mail_last_failed_at', 'mail_last_failed_to']);
        });
    }
};
