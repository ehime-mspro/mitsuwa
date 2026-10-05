<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DAD（土木事業）の SQLite 用スキーマ。今は専門分野マスタと、それを使う協力業者だけ。
 *
 * 本番は raw SQL（`database/sql/create_dad_tables.sql`）で管理し、Laravel マイグレーションに無い。
 * 列は DDL と本番の `SHOW CREATE TABLE dad_specialties`（2026-10-04 に読み取りで確認）に合わせた。
 * 協力業者は専門分野マスタの使用中の確かめに要る列だけを置く（DAD の画面のテストを足すときに広げる）。
 *
 * ⚠ DDL を変えたらこの trait も追従すること。FK は張らない（[[CreatesRealEstateSchema]] と同じ方針）。
 *   本番の `dad_subcontractors.specialty_id` は `ON DELETE SET NULL`。
 */
trait CreatesDadSchema
{
    protected function createDadSchema(): void
    {
        Schema::create('dad_specialties', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50)->unique();
            $t->string('color_bg', 7)->default('#f3f4f6');
            $t->string('color_text', 7)->default('#374151');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('dad_subcontractors', function (Blueprint $t) {
            $t->id();
            $t->string('company_name', 100);
            $t->unsignedBigInteger('specialty_id')->nullable();
            $t->unsignedInteger('created_by');
            $t->timestamps();
            $t->softDeletes();
        });
    }
}
