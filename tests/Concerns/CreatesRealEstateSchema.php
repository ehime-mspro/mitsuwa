<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * re_* テーブルと buyers は本番では raw SQL DDL で管理され、Laravel マイグレーションに無い。
 * テスト（SQLite in-memory）でこれらを使うため、実 DB に準拠した最小スキーマを構築する。
 *
 * - 列名・型・NULL 可否は `php artisan db:table <table>` の実測に合わせる。
 * - FK 制約は SQLite の挙動差・作成順依存を避けるため張らない（挙動テストには不要）。
 * - MySQL の enum 列（re_contracts.department）は SQLite に無いので string で代替する。
 *
 * 既存の CreatesZealSchema と同じ方式。
 */
trait CreatesRealEstateSchema
{
    protected function createRealEstateSchema(): void
    {
        Schema::create('buyers', function (Blueprint $t) {
            $t->id();
            $t->string('last_name', 50);
            $t->string('first_name', 50);
            $t->string('last_name_kana', 50)->nullable();
            $t->string('first_name_kana', 50)->nullable();
            $t->date('birth_date')->nullable();
            $t->string('birth_era', 10)->nullable();
            $t->unsignedTinyInteger('family_adults')->nullable();
            $t->unsignedTinyInteger('family_children')->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('prefecture', 10)->nullable();
            $t->string('city', 50)->nullable();
            $t->string('address_detail', 255)->nullable();
            $t->string('building_name', 255)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email', 255)->nullable();
            $t->string('occupation', 50)->nullable();
            $t->string('employer', 100)->nullable();
            $t->unsignedSmallInteger('years_employed')->nullable();
            $t->text('memo')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('re_suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('supplier_code', 20);
            $t->string('type', 20);
            $t->string('name', 100);
            $t->string('contact_person', 50)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email', 100)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 200)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('re_cost_items', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50);
            $t->integer('sort_order');
            $t->boolean('is_active');
            $t->timestamps();
        });

        Schema::create('re_procurements', function (Blueprint $t) {
            $t->id();
            $t->string('procurement_code', 20)->unique();
            $t->string('property_type', 20);
            $t->string('transaction_type', 20);
            $t->string('status', 20);
            $t->string('property_name', 100);
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 200);
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->decimal('land_area_sqm', 10, 2)->nullable();
            $t->decimal('building_area_sqm', 10, 2)->nullable();
            $t->string('structure', 50)->nullable();
            $t->string('built_year_month', 7)->nullable();
            $t->string('zoning', 50)->nullable();
            $t->decimal('building_coverage', 5, 2)->nullable();
            $t->decimal('floor_area_ratio', 5, 2)->nullable();
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->date('info_obtained_date')->nullable();
            $t->integer('assessment_price')->nullable();
            $t->integer('purchase_price')->nullable();
            $t->integer('target_selling_price')->nullable();
            $t->date('contract_date')->nullable();
            $t->date('settlement_date')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('re_procurement_costs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('procurement_id');
            $t->unsignedBigInteger('cost_item_id');
            $t->integer('estimated_amount');
            $t->integer('actual_amount')->nullable();
            $t->string('notes', 200)->nullable();
            $t->timestamps();
        });

        Schema::create('re_contracts', function (Blueprint $t) {
            $t->id();
            // 実 DB は enum('housing','realestate')。SQLite に enum は無いので string で代替。
            $t->string('department', 20);
            $t->string('contract_type', 30);
            $t->string('status', 20);
            $t->date('contract_date')->nullable();
            $t->string('property_name', 200);
            $t->string('address', 300)->nullable();
            $t->unsignedBigInteger('procurement_id')->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->unsignedBigInteger('lot_id')->nullable();
            $t->unsignedBigInteger('buyer_id')->nullable();
            $t->string('buyer_name', 100)->nullable();
            $t->integer('contract_amount')->nullable();
            $t->integer('cost_amount')->nullable();
            $t->integer('gross_profit')->nullable();
            $t->integer('brokerage_selling_price')->nullable();
            $t->integer('brokerage_fee')->nullable();
            $t->unsignedBigInteger('staff_user_id')->nullable();
            $t->text('memo')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });
    }
}
