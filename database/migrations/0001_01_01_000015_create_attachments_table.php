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
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type', 100)->comment('親モデルのクラス名');
            $table->unsignedBigInteger('attachable_id')->comment('親モデルのID');
            $table->string('file_name', 255)->comment('元のファイル名');
            $table->string('file_path', 500)->comment('保存先パス');
            $table->integer('file_size')->comment('ファイルサイズ（バイト）');
            $table->string('mime_type', 100)->comment('MIMEタイプ');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // インデックス（ポリモーフィック検索用）
            $table->index(['attachable_type', 'attachable_id'], 'idx_attach_morphable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
