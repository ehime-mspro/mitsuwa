<?php

namespace Tests\Feature\Housing;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Feature\Schedule\ScheduleTestCase;

/**
 * 基本情報の「実際の完成日」を「着工予定日」へ付け替える（設計書 §5）。
 *
 * ⚠ **並びは 着工予定日 → 完成予定日。** 逆に置くと、工事の順番と画面の順番が食い違う。
 */
class HousingConstructionStartDateTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_both_tables_have_the_new_column_and_not_the_old_one(): void
    {
        foreach (['hs_properties', 'hs_custom_orders'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'construction_start_date'), "{$table} に着工予定日が無い");
            $this->assertFalse(Schema::hasColumn($table, 'actual_completion_date'), "{$table} に旧列が残っている");
        }
    }

    /**
     * ⚠ **本番 DDL とテスト用スキーマを対で維持する。** 片方だけだと SQLite テストと本番が
     *   黙って drift する（過去に実際に起きている）。
     */
    public function test_the_raw_sql_renames_both_tables(): void
    {
        $sql = file_get_contents(base_path('database/sql/2026-09-02-rename-actual-completion-to-construction-start.sql'));

        foreach (['hs_properties', 'hs_custom_orders'] as $table) {
            $this->assertMatchesRegularExpression(
                '/ALTER TABLE\s+`?' . $table . '`?\s+CHANGE COLUMN\s+`?actual_completion_date`?\s+`?construction_start_date`?/i',
                $sql,
                "{$table} の CHANGE COLUMN が DDL に無い"
            );
        }
    }

    public function test_the_column_is_mass_assignable_and_cast_to_a_date(): void
    {
        foreach ([HsProperty::class, HsCustomOrder::class] as $class) {
            $model = new $class();
            $this->assertContains('construction_start_date', $model->getFillable(), $class);
            $this->assertSame('date', $model->getCasts()['construction_start_date'] ?? null, $class);
            $this->assertNotContains('actual_completion_date', $model->getFillable(), $class);
        }
    }

    public function test_the_property_form_offers_the_construction_start_date_on_create_too(): void
    {
        // ⚠ 旧実装は @if($isEdit) で編集画面にしか出していなかった。着工予定日は登録時から分かる
        $html = $this->actingAs($this->manager())->get('/housing/properties/create')->assertOk()->getContent();

        $this->assertStringContainsString('name="construction_start_date"', $html);
        $this->assertStringContainsString('着工予定日', $html);
        $this->assertStringNotContainsString('name="actual_completion_date"', $html);
        $this->assertStringNotContainsString('実際の完成日', $html);
    }

    public function test_the_custom_order_form_offers_it_too(): void
    {
        $html = $this->actingAs($this->manager())->get('/housing/custom-orders/create')->assertOk()->getContent();

        $this->assertStringContainsString('name="construction_start_date"', $html);
        $this->assertStringNotContainsString('name="actual_completion_date"', $html);
    }

    /** ⚠ 画面の**並び**まで見る。「両方出ている」だけでは順番の入れ替わりを検出できない */
    public function test_the_construction_start_date_comes_before_the_completion_date(): void
    {
        foreach ([
            '/housing/properties/create',
            '/housing/custom-orders/create',
        ] as $url) {
            $html = $this->actingAs($this->manager())->get($url)->assertOk()->getContent();

            $start = strpos($html, 'name="construction_start_date"');
            $end   = strpos($html, 'name="scheduled_completion_date"');

            $this->assertNotFalse($start, $url);
            $this->assertNotFalse($end, $url);
            $this->assertLessThan($end, $start, "{$url}: 着工予定日は完成予定日の前に置く");
        }
    }

    public function test_saving_a_property_stores_the_construction_start_date(): void
    {
        $property = HsProperty::create([
            'property_code' => 'HS-CS1', 'property_name' => '着工テスト', 'status' => 'construction',
            'address' => '愛媛県松山市1-1-1', 'created_by' => 1,
            'construction_start_date' => '2026-02-19',
        ]);

        $this->assertSame('2026-02-19', $property->fresh()->construction_start_date->toDateString());
    }

    /** 和名が無いとエラー文に英字 `construction start date` が出る（Bug #37） */
    public function test_the_validation_attribute_has_a_japanese_name(): void
    {
        $attributes = require base_path('lang/ja/validation.php');

        $this->assertSame('着工予定日', $attributes['attributes']['construction_start_date'] ?? null);
        $this->assertArrayNotHasKey('actual_completion_date', $attributes['attributes']);
    }
}
