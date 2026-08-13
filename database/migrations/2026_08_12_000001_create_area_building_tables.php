<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 周辺ビル調査（テナント管理）の 3 テーブル。
     *
     * ⚠ 本番 DB は database/sql/2026-08-12-create-area-building-tables.sql を直接流して作る。
     *   この migration は SQLite テスト用のミラーで、片方だけ直すとテストだけが落ちる
     *   drift になる。列を足すときは必ず両方を同時に直すこと。
     */
    public function up(): void
    {
        Schema::create('area_buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('ビル名');
            $table->string('address')->nullable()->comment('所在地');
            $table->decimal('latitude', 10, 7)->nullable()->comment('緯度');
            $table->decimal('longitude', 10, 7)->nullable()->comment('経度');
            $table->integer('total_floors')->nullable()->comment('総階数');
            $table->text('notes')->nullable()->comment('備考');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->comment('登録者');
            $table->timestamps();
            $table->softDeletes();

            $table->index('name', 'idx_area_buildings_name');
        });

        Schema::create('area_building_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_building_id')->constrained('area_buildings')->cascadeOnDelete();
            $table->date('surveyed_month')->comment('調査年月。日は 01 固定');
            $table->unsignedInteger('operating_count')->default(0)->comment('営業');
            $table->unsignedInteger('vacant_count')->default(0)->comment('空き');
            $table->unsignedInteger('unknown_count')->default(0)->comment('不明');
            $table->foreignId('surveyed_by')->nullable()->constrained('users')->nullOnDelete()->comment('調査者');
            $table->text('notes')->nullable()->comment('その回の所見');
            $table->timestamps();

            $table->unique(['area_building_id', 'surveyed_month'], 'uk_area_survey_building_month');
        });

        Schema::create('area_building_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_building_id')->constrained('area_buildings')->cascadeOnDelete();
            $table->integer('floor')->nullable()->comment('階。地下は負数（B1 = -1）');
            $table->string('room_number', 50)->nullable()->comment('部屋番号・区画名');
            $table->string('name')->nullable()->comment('テナント名。空き区画の行では NULL');
            $table->string('industry', 100)->nullable()->comment('業種');
            $table->string('status', 20)->comment('operating / vacant / unknown');
            $table->date('confirmed_on')->nullable()->comment('最終確認日');
            $table->date('moved_out_on')->nullable()->comment('退去日');
            $table->text('notes')->nullable()->comment('備考');
            $table->timestamps();

            $table->index(['area_building_id', 'moved_out_on'], 'idx_area_tenants_building_active');
            $table->index('name', 'idx_area_tenants_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_building_tenants');
        Schema::dropIfExists('area_building_surveys');
        Schema::dropIfExists('area_buildings');
    }
};
