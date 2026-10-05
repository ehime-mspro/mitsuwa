<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 構造マスター（`structure_types`）の SQLite 用スキーマ。
 *
 * 本番は raw SQL（`database/sql/create_structure_types.sql`）で管理し、Laravel マイグレーションに無い。
 * 列は本番の `SHOW CREATE TABLE`（2026-10-04 に読み取りで確認）に合わせた。
 * テナント物件の登録・編集画面が `<option>` をここから作る（値は名前。`properties.structure` は文字列で持つ）。
 *
 * ⚠ DDL を変えたらこの trait も追従すること（[[CreatesRealEstateSchema]] と同じ方針・FK は張らない）。
 */
trait CreatesStructureTypeSchema
{
    protected function createStructureTypeSchema(): void
    {
        Schema::create('structure_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }
}
