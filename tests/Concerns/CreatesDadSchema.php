<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DAD（土木事業）の SQLite 用スキーマ（7 表）。
 *
 * 本番は raw SQL（`database/sql/create_dad_tables.sql`）で管理し、Laravel マイグレーションに無い。
 * 列は DDL と本番の `SHOW CREATE TABLE`（7 表とも 2026-10-07 に読み取りで確認。DDL と一致）に合わせた。
 *
 * ⚠ DDL を変えたらこの trait も追従すること。FK は張らない（[[CreatesRealEstateSchema]] と同じ方針）。
 *   本番の外部キー: 協力業者→専門分野 ON DELETE SET NULL／工事案件→発注者・担当者 SET NULL／
 *   原価→工事案件 CASCADE・原価→協力業者 SET NULL／人員配置→工事案件・従業員 CASCADE。
 *   テストでは工事案件・従業員を消しても原価・人員配置が残るので、孤立した行を作らない（従業員の詳細は CASCADE を前提に配置を読む）。
 * ⚠ 一意制約は本番と同じく張る（社員番号・(工事案件, 従業員)）。人員配置の保存は「消して入れ直す」ので、同じトランザクションの中で
 *   同じ従業員を入れ直せることもこの制約の上で確かめられる。
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

        Schema::create('dad_clients', function (Blueprint $t) {
            $t->id();
            $t->string('client_type', 20);
            $t->string('name', 100);
            $t->string('representative', 50)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 200)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('fax', 20)->nullable();
            $t->string('email', 255)->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('created_by');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('dad_subcontractors', function (Blueprint $t) {
            $t->id();
            $t->string('company_name', 100);
            $t->string('representative', 50)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 200)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('fax', 20)->nullable();
            $t->string('email', 255)->nullable();
            $t->unsignedBigInteger('specialty_id')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('created_by');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('dad_employees', function (Blueprint $t) {
            $t->id();
            $t->string('employee_code', 20)->unique('uk_dad_employees_code');
            $t->string('name', 50);
            $t->string('name_kana', 50)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('position', 50)->nullable();
            $t->text('qualifications')->nullable();
            $t->date('hire_date')->nullable();
            $t->string('status', 20)->default('active');
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('dad_projects', function (Blueprint $t) {
            $t->id();
            $t->string('project_code', 20)->unique('uk_dad_projects_code');
            $t->string('project_name', 200);
            $t->string('project_type', 20);
            $t->string('status', 20);
            $t->unsignedBigInteger('client_id')->nullable();
            $t->string('site_address', 300)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->unsignedInteger('estimate_amount')->nullable();
            $t->unsignedInteger('contract_amount')->nullable();
            $t->date('estimate_date')->nullable();
            $t->date('order_date')->nullable();
            $t->date('start_date')->nullable();
            $t->date('completion_date')->nullable();
            $t->date('payment_date')->nullable();
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->unsignedBigInteger('staff_user_id')->nullable();
            $t->text('memo')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('dad_project_costs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id')->index('idx_dad_project_costs_project');
            $t->string('cost_category', 30);
            $t->string('description', 200)->nullable();
            $t->unsignedInteger('estimated_amount')->nullable();
            $t->unsignedInteger('actual_amount')->nullable();
            $t->unsignedBigInteger('subcontractor_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('dad_project_assignments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('employee_id');
            $t->string('role', 50)->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('notes', 200)->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'employee_id'], 'uk_dad_assign_project_emp');
        });
    }
}
