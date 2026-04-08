<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * investments.status に 'completed'（工事完了）を追加
     * VARCHAR型の場合はこのマイグレーション不要（PHPのEnum castで制御される）
     * MySQL ENUM型の場合のみ必要
     */
    public function up(): void
    {
        // MySQL ENUM型の場合のみ実行（VARCHAR型なら何もしない）
        $columnType = DB::selectOne("SHOW COLUMNS FROM investments WHERE Field = 'status'")->Type ?? '';

        if (str_starts_with($columnType, 'enum')) {
            DB::statement("ALTER TABLE investments MODIFY COLUMN status ENUM('planning','in_progress','completed','recovering','recovered') NOT NULL");
        }
    }

    public function down(): void
    {
        $columnType = DB::selectOne("SHOW COLUMNS FROM investments WHERE Field = 'status'")->Type ?? '';

        if (str_starts_with($columnType, 'enum')) {
            DB::statement("ALTER TABLE investments MODIFY COLUMN status ENUM('planning','in_progress','recovering','recovered') NOT NULL");
        }
    }
};
