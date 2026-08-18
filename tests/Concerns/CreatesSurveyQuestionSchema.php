<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アンケート設問（`survey_questions`）の SQLite 用スキーマ。
 *
 * ⚠ **このテーブルはリポジトリのどこにも定義が無い。** Laravel マイグレーションにも
 *   `database/sql/` にも無く、本番で直接作られたまま「正本」が存在しない
 *   （2026-08-18 実測。`ms_*` や `zeal_*` は raw SQL が `database/sql/` にあるので事情が違う）。
 *   そのため列構成は `App\Models\SurveyQuestion` の `$fillable` / `$casts` と
 *   `Admin\SurveyQuestionController` の `validate()` から起こしている。
 *
 * ⚠ **DDL を変えたらこの trait も追従すること。** 片方だけ直すと SQLite テストだけが
 *   落ちる drift になる（本番と実 DB は正常なので不可視）。
 *
 * 既存の [[CreatesMansionSchema]] / [[CreatesZealSchema]] と同じ制約:
 *   - FK は SQLite の挙動差・作成順依存を避けるため張らない
 *   - 列名・NULL 可否・型はモデルの想定に合わせる
 */
trait CreatesSurveyQuestionSchema
{
    protected function createSurveyQuestionSchema(): void
    {
        if (Schema::hasTable('survey_questions')) {
            return;
        }

        Schema::create('survey_questions', function (Blueprint $t) {
            $t->id();
            $t->string('department', 20);
            $t->string('label', 255);
            $t->string('question_type', 20);
            $t->text('options')->nullable();
            $t->text('settings')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }
}
