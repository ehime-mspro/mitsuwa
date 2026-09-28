<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZEAL 経営試算表の 3 テーブルと本部 Sheet 取込の履歴は本番では raw SQL DDL
 * （database/sql/create_zeal_simulation_tables.sql・alter_zeal_simulations_add_sheet_urls.sql・
 * create_zeal_sheet_imports_table.sql）で管理され Laravel マイグレーションに無い。
 * テスト（SQLite in-memory）で使うため DDL に準拠した最小スキーマを構築する。
 *
 * ⚠ **DDL を変えたらこの trait も追従すること。** 片方だけ直すと SQLite テストだけが
 *   落ちる drift になる（本番と実 DB は正常なので不可視）。既存の
 *   [[CreatesZealSchema]] と同じ制約を踏襲する:
 *   - FK は SQLite の挙動差・作成順依存を避けるため張らない（挙動テストには不要）
 *   - 列名・NULL 可否・型は DDL に合わせる
 *   - ENUM は SQLite に無いので string で持つ（値の妥当性はアプリ側の責務）
 *   - 一意の索引は DDL と同じ名前で張る（2026-09-28 に足した。無いと、同じセルの 2 重書きが SQLite では黙って通る）。
 *     ⚠ 今のテストはどれも頼っていない（変異で実測。将来の偽の緑を止めるために持つ。Bug #54 ⑤）
 */
trait CreatesZealSimulationSchema
{
    protected function createZealSimulationSchema(): void
    {
        Schema::create('zeal_simulation_categories', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50);
            $t->string('name', 100);
            $t->string('group_type', 20);   // revenue / member / expense / summary
            $t->string('calc_type', 20);    // manual / fixed / revenue_linked / calculated
            $t->integer('default_amount')->nullable();
            $t->decimal('rate_percent', 6, 3)->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_system')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique('code', 'uq_zeal_sim_cat_code');
        });

        Schema::create('zeal_simulations', function (Blueprint $t) {
            $t->id();
            $t->smallInteger('fiscal_year');
            $t->string('name', 100)->nullable();
            $t->text('notes')->nullable();
            // alter_zeal_simulations_add_sheet_urls.sql
            $t->string('sales_sheet_url', 500)->nullable();
            $t->string('expense_sheet_url', 500)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique('fiscal_year', 'uq_zeal_sim_fiscal_year');
        });

        Schema::create('zeal_simulation_values', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('simulation_id');
            $t->unsignedBigInteger('category_id');
            $t->char('year_month', 7);
            $t->bigInteger('amount')->nullable();
            $t->bigInteger('budget_amount')->nullable();
            $t->boolean('is_manual_override')->default(false);
            $t->timestamps();
            $t->unique(['simulation_id', 'category_id', 'year_month'], 'uq_zeal_sim_val');
        });

        // create_zeal_sheet_imports_table.sql（一意の索引は無い）
        Schema::create('zeal_sheet_imports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('simulation_id');
            $t->string('import_type', 10);   // sales / expense
            $t->char('year_month', 7);
            $t->mediumText('raw_csv')->nullable();
            $t->json('parsed_data')->nullable();
            $t->unsignedBigInteger('imported_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['simulation_id', 'year_month'], 'idx_zeal_sheet_imports_sim_month');
            $t->index('import_type', 'idx_zeal_sheet_imports_type');
        });
    }

    /**
     * 画面が成立する最小限の項目マスタ。
     * DDL の seed（19 項目）のうち、マトリクスの各グループが 1 つずつ揃う分だけ入れる。
     */
    protected function seedZealSimulationCategories(): void
    {
        $rows = [
            ['code' => 'revenue',          'name' => '売上',     'group_type' => 'revenue', 'calc_type' => 'manual',     'sort_order' => 10,  'is_system' => 1],
            ['code' => 'member_count',     'name' => '会員数',   'group_type' => 'member',  'calc_type' => 'manual',     'sort_order' => 20,  'is_system' => 1],
            ['code' => 'rent',             'name' => '賃料',     'group_type' => 'expense', 'calc_type' => 'fixed',      'sort_order' => 30,  'is_system' => 0, 'default_amount' => 200000],
            ['code' => 'expense_total',    'name' => '経費計',   'group_type' => 'summary', 'calc_type' => 'calculated', 'sort_order' => 200, 'is_system' => 1],
            ['code' => 'operating_profit', 'name' => '営業利益', 'group_type' => 'summary', 'calc_type' => 'calculated', 'sort_order' => 210, 'is_system' => 1],
        ];

        foreach ($rows as $row) {
            DB::table('zeal_simulation_categories')->insert($row + [
                'default_amount' => null,
                'rate_percent'   => null,
                'is_active'      => 1,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    /**
     * 本部 Sheet 取込が書く経費の項目（ZealExpenseMapper::WRITABLE_CODES。売上 revenue は上の最小限の項目にある）。
     * 値は DDL の seed（create_zeal_simulation_tables.sql）と insert_zeal_simulation_categories_sheet_import.sql から写した。
     */
    protected function seedZealSheetImportCategories(): void
    {
        $rows = [
            ['code' => 'outsourcing',     'name' => '委託費',           'calc_type' => 'fixed',  'default_amount' => 400000, 'sort_order' => 50],
            ['code' => 'session_fee',     'name' => '時間帯業務委託費', 'calc_type' => 'manual', 'default_amount' => null,   'sort_order' => 65],
            ['code' => 'training_system', 'name' => '研修システム',     'calc_type' => 'fixed',  'default_amount' => 15000,  'sort_order' => 80],
            ['code' => 'web_operation',   'name' => 'web運用',          'calc_type' => 'fixed',  'default_amount' => 15000,  'sort_order' => 90],
            ['code' => 'store_supplies',  'name' => '店舗備品費',       'calc_type' => 'manual', 'default_amount' => null,   'sort_order' => 145],
        ];

        foreach ($rows as $row) {
            DB::table('zeal_simulation_categories')->insert($row + [
                'group_type'   => 'expense',
                'rate_percent' => null,
                'is_system'    => 0,
                'is_active'    => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }
}
