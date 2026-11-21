<?php

namespace Tests\Feature\Housing;

use App\Models\ReProcurementLot;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Support\HousingLandCost;
use Tests\Feature\Housing\Screens\HousingScreenTestCase;

/**
 * 住宅事業の土地原価を紐付け先から計算する部品と、手動 OFF で保存するときの決まり（判断待ち ⑤・案 A＋b）。
 *
 * 設計: docs/superpowers/specs/2026-11-20-housing-land-cost-auto-design.md
 * ⚠ 区画の按分は区画の一覧（ReProject::housingLotOptions()）と同じ式でなければならない（画面が出した値と保存する値が割れる。Bug #41）。
 */
class HousingLandCostTest extends HousingScreenTestCase
{
    private const MANUAL = 5000000;

    // ============================================================
    // 計算（forLink）
    // ============================================================

    public function test_a_lot_is_allocated_like_the_lot_list(): void
    {
        [$project, $two, $four] = $this->pricedProject();

        $this->assertSame(9600000, HousingLandCost::forLink('project_lot', $two->id, null));
        $this->assertSame(14400000, HousingLandCost::forLink('project_lot', (string) $four->id, null));

        $listed = collect(ReProject::with('lots', 'costs')->find($project->id)->housingLotOptions())->pluck('land_cost', 'id');
        $this->assertSame(9600000, $listed[$two->id]);
        $this->assertSame(14400000, $listed[$four->id]);
        $this->assertSame(9600000, $project->fresh()->housingLotLandCost($two->id));
    }

    public function test_a_lot_cannot_be_computed_when_a_lot_has_no_price(): void
    {
        [$project, $two] = $this->pricedProject();
        ReProjectLot::create(['project_id' => $project->id, 'lot_number' => 6, 'area_sqm' => 200, 'area_tsubo' => 60.5, 'selling_price' => null, 'status' => 'on_sale']);

        $this->assertNull(HousingLandCost::forLink('project_lot', $two->id, null));
    }

    public function test_a_procurement_uses_actual_or_estimated_costs(): void
    {
        $this->assertSame(7850000, HousingLandCost::forLink('procurement', null, $this->costedProcurement()->id));

        $bare = $this->procurement(['procurement_code' => 'RE-PRC-002']);
        $this->assertNull(HousingLandCost::forLink('procurement', null, $bare->id), '原価の行が無い仕入れ案件を計算できるとした');
    }

    public function test_a_procurement_lot_is_allocated_or_whole_by_the_number_of_lots(): void
    {
        $procurement = $this->costedProcurement(); // 原価 7,850,000
        $one = ReProcurementLot::create(['procurement_id' => $procurement->id, 'lot_number' => 1, 'area_sqm' => 100, 'selling_price' => 10000000, 'status' => 'on_sale']);

        // 1 区画: 区画なしでも区画ありでも全額
        $this->assertSame(7850000, HousingLandCost::forLink('procurement', null, $procurement->id));
        $this->assertSame(7850000, HousingLandCost::forLink('procurement', null, $procurement->id, $one->id));

        $two = ReProcurementLot::create(['procurement_id' => $procurement->id, 'lot_number' => 2, 'area_sqm' => 100, 'selling_price' => 30000000, 'status' => 'on_sale']);

        // 2 区画以上: 区画ごとの按分（分譲地と同じ式）。区画なしは空
        $this->assertSame(1962500, HousingLandCost::forLink('procurement', null, $procurement->id, (string) $one->id));
        $this->assertSame(5887500, HousingLandCost::forLink('procurement', null, $procurement->id, $two->id));
        $this->assertNull(HousingLandCost::forLink('procurement', null, $procurement->id));
        $this->assertNull(HousingLandCost::forLink('procurement', null, $procurement->id, 'abc'));
        $this->assertNull(HousingLandCost::forLink('procurement', null, $procurement->id, 99999));
    }

    public function test_a_procurement_without_cost_rows_stays_empty_even_with_a_lot(): void
    {
        $bare = $this->procurement(['procurement_code' => 'RE-PRC-003']);
        $lot = ReProcurementLot::create(['procurement_id' => $bare->id, 'lot_number' => 1, 'area_sqm' => 100, 'selling_price' => 10000000, 'status' => 'on_sale']);

        $this->assertNull(HousingLandCost::forLink('procurement', null, $bare->id, $lot->id));
        $this->assertSame([null], array_column($bare->fresh()->housingLotOptions(), 'land_cost'));
    }

    public function test_other_links_cannot_be_computed(): void
    {
        [, $two] = $this->pricedProject();
        $procurement = $this->costedProcurement();

        $this->assertNull(HousingLandCost::forLink('customer_land', $two->id, $procurement->id));
        $this->assertNull(HousingLandCost::forLink(null, $two->id, $procurement->id));
        $this->assertNull(HousingLandCost::forLink('', $two->id, $procurement->id));
        $this->assertNull(HousingLandCost::forLink('project_lot', 99999, null));
        $this->assertNull(HousingLandCost::forLink('project_lot', null, null));
        $this->assertNull(HousingLandCost::forLink('project_lot', 'abc', null));
        $this->assertNull(HousingLandCost::forLink('procurement', null, 99999));
    }

    // ============================================================
    // 決まり（decide・whenAutomatic）
    // ============================================================

    public function test_decide_computes_only_when_the_save_says_so(): void
    {
        foreach ([false, true] as $creating) {
            foreach ([false, true] as $savedManual) {
                foreach ([false, true] as $linkChanged) {
                    foreach ([false, true] as $refresh) {
                        $expected = ($creating || $savedManual || $linkChanged || $refresh) ? 9600000 : self::MANUAL;
                        $this->assertSame(
                            $expected,
                            HousingLandCost::decide($creating, $savedManual, $linkChanged, $refresh, 9600000, self::MANUAL),
                            json_encode(compact('creating', 'savedManual', 'linkChanged', 'refresh'))
                        );
                    }
                }
            }
        }

        $this->assertNull(HousingLandCost::decide(false, true, false, false, null, self::MANUAL), '計算できないときに保存済みの値を残した');
        $this->assertNull(HousingLandCost::decide(false, false, false, false, 9600000, null), '保存済みが空なのに計算値を入れた');
    }

    public function test_when_automatic_reads_the_saved_row(): void
    {
        [, $two, $four] = $this->pricedProject();
        $link = ['land_source_type' => 'project_lot', 're_project_lot_id' => (string) $two->id];

        $this->assertSame(9600000, HousingLandCost::whenAutomatic(null, $link, false), '新規登録で計算しなかった');

        $manual = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $two->id, 'land_cost' => self::MANUAL, 'is_land_cost_manual' => true]);
        $this->assertSame(9600000, HousingLandCost::whenAutomatic($manual, $link, false), '手入力をやめたのに保存済みの値を残した');

        $automatic = $this->property(['property_code' => 'HS-002', 'land_source_type' => 'project_lot', 're_project_lot_id' => $two->id, 'land_cost' => self::MANUAL, 'is_land_cost_manual' => false]);
        $this->assertSame(self::MANUAL, HousingLandCost::whenAutomatic($automatic, $link, false), 'チェックに触らない保存で値が動いた');
        $this->assertSame(9600000, HousingLandCost::whenAutomatic($automatic, $link, true), '「今の計算値にする」で計算しなかった');

        // 保存済みは getOriginal() で読む（保存の前に属性へ値を入れても決まりは変わらない）
        $automatic->is_land_cost_manual = true;
        $automatic->land_cost = 123;
        $this->assertSame(self::MANUAL, HousingLandCost::whenAutomatic($automatic, $link, false), '保存していない属性で決めた');

        $order = $this->customOrder(['land_source_type' => 'project_lot', 're_project_lot_id' => $two->id, 'land_cost' => self::MANUAL, 'is_land_cost_manual' => false]);
        $this->assertSame(14400000, HousingLandCost::whenAutomatic($order, ['land_source_type' => 'project_lot', 're_project_lot_id' => (string) $four->id], false), '紐付け先を変えたのに計算しなかった');
    }

    public function test_link_differs_ignores_the_contract(): void
    {
        [, $two, $four] = $this->pricedProject();
        $property = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $two->id]);

        $this->assertTrue($property->landLinkDiffers(['land_source_type' => 'project_lot', 're_project_lot_id' => (string) $four->id]));
        $this->assertTrue($property->landLinkDiffers(['land_source_type' => 'customer_land']));
        $this->assertFalse($property->landLinkDiffers(['land_source_type' => 'project_lot', 're_project_lot_id' => '0' . $two->id]));
        $this->assertFalse($property->landLinkDiffers(['land_source_type' => 'project_lot', 're_project_lot_id' => $two->id, 're_procurement_id' => '9']));
        $this->assertFalse($property->refusesLandChange(['land_source_type' => 'customer_land']), '契約の無い建売の紐付けを固定した');
    }

    // ============================================================
    // 仕入れ案件の情報の API
    // ============================================================

    public function test_the_procurement_info_returns_the_land_cost(): void
    {
        $procurement = $this->costedProcurement();
        $this->assertSame(7850000, $this->apiResponse(route('api.housing.procurement-info', $procurement))['body']['land_cost']);

        $bare = $this->procurement(['procurement_code' => 'RE-PRC-002']);
        $body = $this->apiResponse(route('api.housing.procurement-info', $bare))['body'];
        $this->assertArrayHasKey('land_cost', $body);
        $this->assertNull($body['land_cost']);
    }
}
