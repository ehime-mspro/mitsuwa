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
 * ⚠ **住宅事業の ◆ は「着工」と「完成」の 2 つ**（設計書 §6）。別々の節目なので 2 つ描いてよい。
 *   ⚠ 不動産（仕入れ案件 / 分譲地PJ）の ◆ は変えない。
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
    // 住宅事業の ◆ は着工と完成の 2 つ（設計書 §6）／不動産の ◆ は変えない
    // ============================================================

    public function test_a_property_draws_a_milestone_for_the_construction_start_and_the_completion(): void
    {
        $owner = $this->property([
            'construction_start_date'   => '2026-02-19',
            'scheduled_completion_date' => '2026-09-27',
        ]);
        $owner->scheduleSteps()->create([
            'name' => '基礎工事', 'category' => 'work',
            'planned_start' => '2026-03-01', 'planned_end' => '2026-03-20', 'sort_order' => 1,
        ]);

        $milestones = $owner->autoMilestones();

        $this->assertSame(['着工', '完成'], $this->labels($milestones));
        $this->assertSame('2026-02-19', $milestones[0]['date']->toDateString());
        $this->assertSame('2026-09-27', $milestones[1]['date']->toDateString());
    }

    public function test_a_property_with_only_one_of_the_two_dates_draws_only_that_one(): void
    {
        $onlyStart = $this->property([
            'property_code'           => 'HS-ONLY-START',
            'construction_start_date' => '2026-02-19',
        ]);
        $this->assertSame(['着工'], $this->labels($onlyStart->autoMilestones()));

        $onlyEnd = $this->property([
            'property_code'             => 'HS-ONLY-END',
            'scheduled_completion_date' => '2026-09-27',
        ]);
        $this->assertSame(['完成'], $this->labels($onlyEnd->autoMilestones()));
    }

    public function test_a_custom_order_keeps_its_contract_and_delivery_milestones(): void
    {
        $owner = $this->customOrder([
            'contract_date'             => '2026-01-10',
            'construction_start_date'   => '2026-02-19',
            'scheduled_completion_date' => '2026-09-27',
            'delivery_date'             => '2026-10-15',
        ]);

        $this->assertSame(
            ['契約', '着工', '完成', '引渡し'],
            $this->labels($owner->autoMilestones()),
            '注文住宅は契約・引渡しも節目に持つ（順序は日付の並びどおり）'
        );
    }

    /**
     * ⚠ 不動産の ◆ は変えない（巻き込み事故の検出。Bug #41）。
     *
     * ⚠ **これ単体では弱いテスト。** `ReProcurement` が自分で `autoMilestones()` を宣言していることと
     *   `scheduleTracksActuals()` が true であることしか見ておらず、`autoMilestones()` の中身
     *   （ラベル・順序）を書き換えても、このテスト単体は緑のまま通ることを実測済み
     *   （`--filter test_the_realestate_milestones_are_untouched` で確認）。
     *   実際の出力は次の `test_the_realestate_milestones_show_the_real_labels_in_order` で固定する。
     */
    public function test_the_realestate_milestones_are_untouched(): void
    {
        $before = (new \ReflectionMethod(\App\Models\ReProcurement::class, 'autoMilestones'))->getDeclaringClass()->getName();

        $this->assertSame(\App\Models\ReProcurement::class, $before);
        $this->assertTrue((new \App\Models\ReProcurement())->scheduleTracksActuals());
    }

    /**
     * 上のテストの弱さを補う。`ReProcurement::autoMilestones()` / `ReProject::autoMilestones()` は
     * どちらも同じ実装（`契約` → `決済` の固定順で、それぞれ null なら array_filter で除外）と
     * 読んで確認したうえで期待値を決めている（推測で書いていない）。
     *
     * ⚠ **不動産の親は 2 つ**（仕入れ案件 / 分譲地PJ）。片方だけ見ると、もう片方の
     *   `autoMilestones()` を書き換える変異を検出できない（Bug #44 と同型）ので対称に見る。
     */
    public function test_the_realestate_milestones_show_the_real_labels_in_order(): void
    {
        $cases = [
            'procurement' => [
                $this->procurement([
                    'contract_date'   => '2026-03-02',
                    'settlement_date' => '2026-07-18',
                ]),
                '2026-03-02',
                '2026-07-18',
            ],
            'project' => [
                $this->project([
                    'contract_date'   => '2026-04-06',
                    'settlement_date' => '2026-08-21',
                ]),
                '2026-04-06',
                '2026-08-21',
            ],
        ];

        foreach ($cases as $label => [$owner, $contractDate, $settlementDate]) {
            $m = $owner->autoMilestones();

            $this->assertSame(['契約', '決済'], $this->labels($m), $label);
            $this->assertSame($contractDate, $m[0]['date']->toDateString(), $label);
            $this->assertSame($settlementDate, $m[1]['date']->toDateString(), $label);
        }
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
