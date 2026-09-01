<?php

namespace Tests\Feature\Schedule;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\ReProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 自動マイルストーン（設計書 §3.4）。既存の日付列から ◆ を描く。工程行としては作らない。
 *
 * ⚠ **`scheduled_completion_date` と `actual_completion_date` は同じ「完成」という 1 つの節目。**
 *   ◆ を 2 つ描くと「完成が 2 回ある」ように見える。実績があれば実績、無ければ予定で 1 つだけ。
 *
 * ⚠ 親ごとにアクセサ名が違う（procurement_code / project_code / property_code / order_code）ので、
 *   trait 経由で読めることも 4 親ぶん対称に固定する（直に $model->name と書くと静かに空欄になる）。
 */
class ScheduleAutoMilestoneTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function procurement(array $attrs = []): ReProcurement
    {
        return ReProcurement::create(array_merge([
            'procurement_code' => 'PRC-001',
            'property_type'    => 'used_house',
            'transaction_type' => 'purchase',
            'status'           => 'contracted',
            'property_name'    => '井門町 更地',
            'address'          => '愛媛県松山市1-1-1',
            'created_by'       => 1,
        ], $attrs));
    }

    private function project(array $attrs = []): ReProject
    {
        return ReProject::create(array_merge([
            'project_code' => 'PRJ-001',
            'project_name' => '余戸南 分譲地',
            'status'       => 'selling',
            'address'      => '愛媛県松山市2-2-2',
            'created_by'   => 1,
        ], $attrs));
    }

    private function property(array $attrs = []): HsProperty
    {
        return HsProperty::create(array_merge([
            'property_code' => 'HS-001',
            'property_name' => '余戸南 3号地',
            'status'        => 'construction',
            'address'       => '愛媛県松山市3-3-3',
            'created_by'    => 1,
        ], $attrs));
    }

    private function customOrder(array $attrs = []): HsCustomOrder
    {
        return HsCustomOrder::create(array_merge([
            'order_code'    => 'CO-001',
            'order_name'    => '松山市 T様邸',
            'status'        => 'construction',
            'customer_name' => 'T様',
            'address'       => '愛媛県松山市4-4-4',
            'created_by'    => 1,
        ], $attrs));
    }

    /** @return list<string> ラベルだけ取り出す */
    private function labels(array $milestones): array
    {
        return array_column($milestones, 'label');
    }

    // ============================================================
    // 設計書 §3.4 の表どおりであること
    // ============================================================

    public function test_procurement_shows_contract_and_settlement(): void
    {
        $m = $this->procurement([
            'contract_date'   => '2026-01-23',
            'settlement_date' => '2026-05-29',
        ])->autoMilestones();

        $this->assertSame(['契約', '決済'], $this->labels($m));
        $this->assertSame('2026-01-23', $m[0]['date']->toDateString());
        $this->assertSame('2026-05-29', $m[1]['date']->toDateString());
    }

    public function test_project_shows_contract_and_settlement(): void
    {
        $m = $this->project([
            'contract_date'   => '2026-02-10',
            'settlement_date' => '2026-06-30',
        ])->autoMilestones();

        $this->assertSame(['契約', '決済'], $this->labels($m));
    }

    public function test_null_dates_produce_no_milestone(): void
    {
        $this->assertSame([], $this->procurement()->autoMilestones());
        $this->assertSame([], $this->project()->autoMilestones());
        $this->assertSame([], $this->property()->autoMilestones());
        $this->assertSame([], $this->customOrder()->autoMilestones());
    }

    // ============================================================
    // 完成の ◆ は 1 つだけ
    // ============================================================

    /**
     * ⚠ **これが「◆ が 2 つ出る」変異を止めるテスト。**
     */
    public function test_property_completion_is_a_single_milestone_even_when_both_dates_exist(): void
    {
        $m = $this->property([
            'scheduled_completion_date' => '2026-12-11',
            'actual_completion_date'    => '2026-11-28',
        ])->autoMilestones();

        $this->assertSame(['完成'], $this->labels($m), '完成の ◆ を 2 つ描かないこと（設計書 §3.4）');
        $this->assertSame('2026-11-28', $m[0]['date']->toDateString(), '実績があれば実績を採る');
    }

    public function test_property_falls_back_to_the_scheduled_completion(): void
    {
        $m = $this->property(['scheduled_completion_date' => '2026-12-11'])->autoMilestones();

        $this->assertSame(['完成'], $this->labels($m));
        $this->assertSame('2026-12-11', $m[0]['date']->toDateString());
    }

    public function test_custom_order_shows_contract_completion_and_delivery(): void
    {
        $m = $this->customOrder([
            'contract_date'             => '2026-04-18',
            'scheduled_completion_date' => '2026-11-20',
            'actual_completion_date'    => '2026-11-15',
            'delivery_date'             => '2026-11-30',
        ])->autoMilestones();

        $this->assertSame(['契約', '完成', '引渡し'], $this->labels($m));
        $this->assertSame('2026-11-15', $m[1]['date']->toDateString(), '完成は実績を優先し 1 つだけ');
    }

    // ============================================================
    // trait 経由で親の差を吸収できていること
    // ============================================================

    public function test_every_parent_exposes_its_code_name_department_and_url(): void
    {
        $cases = [
            [$this->procurement(), 'PRC-001', '井門町 更地', 'realestate.procurements', '/realestate/procurements/'],
            [$this->project(),     'PRJ-001', '余戸南 分譲地', 'realestate.projects',     '/realestate/projects/'],
            [$this->property(),    'HS-001',  '余戸南 3号地', 'housing.properties',      '/housing/properties/'],
            [$this->customOrder(), 'CO-001',  '松山市 T様邸', 'housing.custom-orders',   '/housing/custom-orders/'],
        ];

        foreach ($cases as [$model, $code, $name, $prefix, $urlFragment]) {
            $class = $model::class;

            $this->assertSame($code, $model->scheduleCode(), "{$class}: コード列が違う");
            $this->assertSame($name, $model->scheduleName(), "{$class}: 名称列が違う");
            $this->assertSame($prefix, $model->scheduleRoutePrefix(), "{$class}: ルート接頭辞が違う");
            $this->assertSame(explode('.', $prefix)[0], $model->scheduleDepartment(), "{$class}: 部署が違う");
            $this->assertStringContainsString($urlFragment, $model->scheduleUrl(), "{$class}: 詳細 URL が違う");
        }
    }

    public function test_schedule_steps_come_back_in_sort_order(): void
    {
        $p = $this->procurement();

        foreach ([['C', 3], ['A', 1], ['B', 2]] as [$name, $order]) {
            $p->scheduleSteps()->create([
                'name' => $name, 'category' => 'work', 'sort_order' => $order,
            ]);
        }

        $this->assertSame(['A', 'B', 'C'], $p->scheduleSteps()->pluck('name')->all());
    }

    /**
     * ⚠ **id は 4 親のあいだで衝突する**（別テーブルなので）。
     *   型も一緒に見ていないと他人の工程を拾う。
     */
    public function test_steps_are_scoped_by_type_as_well_as_id(): void
    {
        $proc = $this->procurement();
        $prop = $this->property();

        $this->assertSame($proc->getKey(), $prop->getKey(), '前提: 同じ id の別テーブル行を作れている');

        $proc->scheduleSteps()->create(['name' => '仕入れ側の工程', 'category' => 'work']);

        $this->assertCount(1, $proc->scheduleSteps()->get());
        $this->assertCount(0, $prop->scheduleSteps()->get(), '型で絞れていないと他人の工程が見える');
    }
}
