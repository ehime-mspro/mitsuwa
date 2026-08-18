<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 賃貸マンション（`ms_*`）の SQLite 用スキーマ。
 *
 * 本番では raw SQL（`database/sql/create_mansion_tables.sql` ＋
 * `2026-08-17-add-mansion-deposit-settlement.sql`）で管理され Laravel マイグレーションに無い。
 *
 * ⚠ **DDL を変えたらこの trait も追従すること。** 片方だけ直すと SQLite テストだけが
 *   落ちる drift になる（本番と実 DB は正常なので不可視）。
 *
 * 既存の [[CreatesZealSchema]] / [[CreatesZealSimulationSchema]] と同じ制約:
 *   - FK は SQLite の挙動差・作成順依存を避けるため張らない（挙動テストには不要）
 *   - 列名・NULL 可否・型は DDL に合わせる
 */
trait CreatesMansionSchema
{
    protected function createMansionSchema(): void
    {
        Schema::create('ms_properties', function (Blueprint $t) {
            $t->id();
            $t->string('property_code', 20);
            $t->string('property_name', 100);
            $t->string('ownership_type', 20);
            $t->string('owner_name', 100)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 200);
            $t->unsignedSmallInteger('total_units')->nullable();
            $t->unsignedTinyInteger('total_floors')->nullable();
            $t->string('structure', 50)->nullable();
            $t->string('built_year_month', 7)->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->unique('property_code', 'uk_ms_properties_code');
            $t->timestamps();
        });

        Schema::create('ms_rooms', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_id');
            $t->string('room_number', 20);
            $t->unsignedTinyInteger('floor')->nullable();
            $t->string('room_type', 20)->nullable();
            $t->decimal('area_sqm', 8, 2)->nullable();
            $t->string('status', 20);
            $t->unsignedInteger('rent')->nullable();
            $t->unsignedInteger('common_fee')->nullable();
            $t->unsignedInteger('deposit')->nullable();
            $t->unsignedInteger('key_money')->nullable();
            $t->text('notes')->nullable();
            $t->unique(['property_id', 'room_number'], 'uk_ms_rooms_property_room');
            $t->timestamps();
        });

        Schema::create('ms_tenants', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_type', 20);
            $t->string('name', 100);
            $t->string('phone', 20)->nullable();
            $t->string('email')->nullable();
            $t->string('workplace', 100)->nullable();
            $t->string('emergency_contact_name', 100)->nullable();
            $t->string('emergency_contact_phone', 20)->nullable();
            $t->string('emergency_contact_relation', 50)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('ms_contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('room_id');
            $t->unsignedBigInteger('tenant_id');
            $t->string('status', 20);
            $t->date('contract_date')->nullable();
            $t->date('move_in_date')->nullable();
            $t->date('move_out_date')->nullable();
            // 敷金精算（2026-08-17 追加）
            $t->string('termination_reason', 200)->nullable();
            $t->unsignedInteger('restoration_cost')->nullable();
            $t->unsignedInteger('cleaning_cost')->nullable();
            $t->unsignedInteger('deposit_at_settlement')->nullable();
            $t->unsignedInteger('rent')->nullable();
            $t->unsignedInteger('common_fee')->nullable();
            $t->unsignedInteger('deposit')->nullable();
            $t->unsignedInteger('key_money')->nullable();
            $t->unsignedBigInteger('staff_user_id')->nullable();
            $t->text('memo')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('ms_contract_deductions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('contract_id');
            $t->string('name', 100);
            $t->unsignedInteger('amount');
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('ms_contract_revisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('contract_id');
            $t->date('revision_date');
            $t->unsignedInteger('new_rent')->nullable();
            $t->unsignedInteger('new_common_fee')->nullable();
            $t->string('reason', 200)->nullable();
            $t->unsignedInteger('created_by');
            $t->timestamps();
        });

        Schema::create('ms_parkings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_id');
            $t->string('parking_number', 20);
            $t->unsignedInteger('monthly_fee');
            $t->string('status', 20);
            $t->boolean('has_roof')->nullable();
            $t->text('notes')->nullable();
            $t->unique(['property_id', 'parking_number'], 'uk_ms_parkings_property_number');
            $t->timestamps();
        });

        Schema::create('ms_parking_contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('parking_id');
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('contract_id')->nullable();
            $t->string('status', 20);
            $t->date('contract_date')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->unsignedInteger('monthly_fee');
            $t->unsignedInteger('deposit')->nullable();
            $t->unsignedBigInteger('staff_user_id')->nullable();
            $t->text('memo')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });
    }
}
