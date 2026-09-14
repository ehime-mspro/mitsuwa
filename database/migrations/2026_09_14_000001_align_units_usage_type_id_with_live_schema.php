<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 区画（units）の用途を本番のスキーマに揃える（SQLite のテストのための鏡。本番は raw SQL で管理している）。
 *
 * 本番は `database/sql/add_usage_type_id_to_units.sql` で `usage_type_id`（用途マスター inquiry_usage_types への参照）を足し、
 * 旧 enum の `usage_type` を落としている。この migration が無いと、テストの units に `usage_type_id` が無く、
 * 区画の CSV 取込の確定（`usage_type_id` を必ず書く）がテストで一度も通せなかった。
 *
 * ⚠ 外部キーは付けない。本番には `fk_units_usage_type`（ON DELETE SET NULL）があるが、SQLite で外部キーを足すと
 *   テーブルが作り直され、`units.status` の CHECK（enum）が消える。状態の CHECK のほうをテストで守りたいので、
 *   列だけを足す（TenantUnitImportTest のカナリアが、一意制約と状態の CHECK が残っていることを見ている）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('usage_type');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->unsignedBigInteger('usage_type_id')->nullable()->after('area_tsubo')->comment('用途（inquiry_usage_types）');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('usage_type_id');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->enum('usage_type', ['shop', 'warehouse', 'office', 'other'])->nullable()->after('area_tsubo')->comment('用途');
        });
    }
};
