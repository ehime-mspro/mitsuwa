<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * zeal_* テーブルは本番では raw SQL DDL（database/sql/create_zeal_tables.sql）で
 * 管理され Laravel マイグレーションに無い。テスト（SQLite in-memory）で
 * これらを使うため、DDL に準拠した最小スキーマを構築する。
 *
 * - FK 制約は SQLite の挙動差・作成順依存を避けるため張らない（挙動テストには不要）。
 * - 列名・NULL 可否・型は create_zeal_tables.sql に合わせる。
 */
trait CreatesZealSchema
{
    protected function createZealSchema(): void
    {
        Schema::create('zeal_stores', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('address', 300)->nullable();
            $t->string('phone', 20)->nullable();
            $t->date('open_date')->nullable();
            $t->integer('display_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('zeal_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->unsignedInteger('regular_price_excl');
            $t->unsignedInteger('campaign_price_excl')->nullable();
            $t->date('campaign_starts_on')->nullable();
            $t->date('campaign_ends_on')->nullable();
            $t->integer('max_concurrent_reservations')->nullable();
            $t->boolean('includes_personal')->default(false);
            $t->boolean('includes_semi_personal')->default(false);
            $t->integer('monthly_session_limit')->nullable();
            $t->boolean('is_pair_plan')->default(false);
            $t->integer('display_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('zeal_trainers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->integer('display_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('zeal_members', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id');
            $t->integer('gym_inquiry_id')->nullable();
            $t->string('name', 100);
            $t->string('name_kana', 100)->nullable();
            $t->string('gender', 10)->nullable();
            $t->date('birthday')->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email', 100)->nullable();
            $t->string('postal_code', 8)->nullable();
            $t->string('address', 300)->nullable();
            $t->date('joined_on');
            $t->date('withdrew_on')->nullable();
            $t->string('withdraw_reason', 50)->nullable();
            $t->text('withdraw_note')->nullable();
            $t->unsignedBigInteger('current_plan_id')->nullable();
            $t->unsignedBigInteger('trainer_id')->nullable();
            $t->unsignedBigInteger('pair_parent_member_id')->nullable();
            $t->string('acquisition_source', 30)->nullable();
            $t->string('purpose', 50)->nullable();
            $t->text('memo')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('zeal_member_contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('member_id');
            $t->unsignedBigInteger('plan_id');
            $t->date('period_start');
            $t->date('period_end')->nullable();
            $t->unsignedInteger('applied_price_excl');
            $t->boolean('is_campaign_applied')->default(false);
            $t->decimal('tax_rate_at_contract', 5, 2);
            $t->string('change_reason', 50)->nullable();
            $t->string('note', 200)->nullable();
            $t->unsignedInteger('created_by');
            $t->timestamps();
        });
    }
}
