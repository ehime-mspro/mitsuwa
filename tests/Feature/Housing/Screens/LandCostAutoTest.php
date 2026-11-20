<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProjectLot;

/**
 * 土地原価の手動入力をやめたら紐付け先から計算し直す（判断待ち ⑤・案 A＋b）。
 * 設計: docs/superpowers/specs/2026-11-20-housing-land-cost-auto-design.md
 *
 * 手動 OFF の保存では、送られた土地原価を使わずサーバーが決める（App\Support\HousingLandCost::whenAutomatic()）:
 * 新規登録／保存済みが手動 ON／紐付け先が保存済みと違う／「今の計算値にする」を押した → 紐付け先から計算、
 * それ以外（チェックに触らず保存）→ 保存済みの値のまま（「古い契約の原価が勝手に動かないように」）。
 *
 * ⚠ 値は HousingScreenTestCase::pricedProject()（区画 2 → 9,600,000・区画 4 → 14,400,000）と
 *   costedProcurement()（7,850,000）。手入力の値は 5,000,000。
 */
class LandCostAutoTest extends HousingScreenTestCase
{
    private const MANUAL = 5000000;

    // ============================================================
    // 建売の登録・編集
    // ============================================================

    private function propertyEditForm(HsProperty $property, string $js = ''): array
    {
        return $this->browserForm($this->htmlOf(route('housing.properties.edit', $property)),
            'action="' . route('housing.properties.update', $property) . '"', 'housingPropertyForm', $js);
    }

    private function saveProperty(HsProperty $property, array $form): string
    {
        return $this->landed($this->submit($form, route('housing.properties.edit', $property)));
    }

    /** 区画 2 に建つ建売（土地原価 5,000,000） */
    private function propertyOnLot(bool $manual, ?ReProjectLot $lot = null, array $overrides = []): HsProperty
    {
        $lot ??= $this->pricedProject()[1];

        return $this->property(array_merge([
            'land_source_type' => 'project_lot', 're_project_lot_id' => $lot->id,
            'land_cost' => self::MANUAL, 'is_land_cost_manual' => $manual,
        ], $overrides));
    }

    public function test_turning_manual_off_on_a_property_computes_the_land_cost(): void
    {
        $property = $this->propertyOnLot(true);

        $html = $this->saveProperty($property, $this->fill($this->propertyEditForm($property), ['is_land_cost_manual' => '0']));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $property->refresh();
        $this->assertSame([9600000, false], [$property->land_cost, $property->is_land_cost_manual], '手入力をやめたのに前の値が残った（⑤）');
    }

    public function test_an_untouched_automatic_property_keeps_its_saved_value(): void
    {
        $property = $this->propertyOnLot(false);

        $this->saveProperty($property, $this->fill($this->propertyEditForm($property), ['land_cost' => '7000000', 'notes' => '外構は別途']));

        $property->refresh();
        $this->assertSame([self::MANUAL, '外構は別途'], [$property->land_cost, $property->notes], '手動 OFF で送られた値を保存した／チェックに触らない保存で値が動いた');
    }

    public function test_refresh_sets_the_current_computed_value(): void
    {
        $property = $this->propertyOnLot(false);
        $form = $this->propertyEditForm($property);
        $form['fields']['refresh_land_cost'] = '1';

        $this->saveProperty($property, $form);

        $this->assertSame(9600000, $property->fresh()->land_cost, '「今の計算値にする」で計算値にならない');
    }

    public function test_manual_on_saves_the_sent_value_even_with_refresh(): void
    {
        $property = $this->propertyOnLot(false);
        $form = $this->fill($this->propertyEditForm($property), ['is_land_cost_manual' => '1', 'land_cost' => '6000000']);
        $form['fields']['refresh_land_cost'] = '1';

        $this->saveProperty($property, $form);

        $property->refresh();
        $this->assertSame([6000000, true], [$property->land_cost, $property->is_land_cost_manual]);
    }

    public function test_a_new_property_computes_regardless_of_the_sent_value(): void
    {
        [$project, $two] = $this->pricedProject();
        $url = route('housing.properties.create');
        $needle = 'action="' . route('housing.properties.store') . '"';
        $form = $this->browserForm($this->htmlOf($url), $needle, 'housingPropertyForm',
            'data.landSourceType = "project_lot"; data.onSourceTypeChange(); data.selectedProjectId = "' . $project->id . '"; data.onProjectChange();
             setImmediate(function () { data.selectedLotId = "' . $two->id . '"; data.onLotChange(); });',
            [$this->apiResponse(route('api.housing.project-lots', ['project_id' => $project->id, 'exclude_hs' => 1]))]);
        $form = $this->fill($form, ['property_name' => '平井 建売 2', 'land_cost' => '7000000', 'is_land_cost_manual' => '0']);

        $this->landed($this->submit($form, $url));

        $this->assertSame(9600000, HsProperty::where('property_name', '平井 建売 2')->firstOrFail()->land_cost, '新規登録で送られた値を保存した');

        // 紐付けの無い建売は計算できない（空）
        $form = $this->fill($this->browserForm($this->htmlOf($url), $needle, 'housingPropertyForm'),
            ['property_name' => '紐付けなし', 'address' => '愛媛県松山市1', 'land_cost' => '7000000', 'is_land_cost_manual' => '0']);
        $this->landed($this->submit($form, $url));
        $this->assertNull(HsProperty::where('property_name', '紐付けなし')->firstOrFail()->land_cost);
    }

    public function test_an_uncomputable_lot_saves_empty(): void
    {
        [$project, $two] = $this->pricedProject();
        ReProjectLot::create(['project_id' => $project->id, 'lot_number' => 6, 'area_sqm' => 200, 'area_tsubo' => 60.5, 'selling_price' => null, 'status' => 'on_sale']);
        $property = $this->propertyOnLot(true, $two);

        $this->saveProperty($property, $this->fill($this->propertyEditForm($property), ['is_land_cost_manual' => '0']));

        $this->assertNull($property->fresh()->land_cost, '計算できないのに前の手入力の値が残った');
    }

    // ============================================================
    // 注文住宅の編集
    // ============================================================

    private function orderEditForm(HsCustomOrder $order, string $js = ''): array
    {
        return $this->browserForm($this->htmlOf(route('housing.custom-orders.edit', $order)),
            'action="' . route('housing.custom-orders.update', $order) . '"', 'customOrderForm', $js);
    }

    private function saveOrder(HsCustomOrder $order, array $form): string
    {
        return $this->landed($this->submit($form, route('housing.custom-orders.edit', $order)));
    }

    public function test_changing_the_lot_of_an_open_order_computes_for_the_new_lot(): void
    {
        [, $two, $four] = $this->pricedProject();
        $order = $this->customOrder(['land_source_type' => 'project_lot', 're_project_lot_id' => $two->id, 'land_cost' => 9600000, 'is_land_cost_manual' => false]);
        $form = $this->orderEditForm($order, 'data.selectedLotId = "' . $four->id . '"; data.onLotChange();');

        $this->saveOrder($order, $this->fill($form, ['land_cost' => '7000000']));

        $order->refresh();
        $this->assertSame([$four->id, 14400000], [$order->re_project_lot_id, $order->land_cost], '紐付け先を変えたのに新しい区画から計算していない');
    }

    public function test_a_contracted_order_follows_the_same_rules(): void
    {
        [, $two] = $this->pricedProject('sold');
        $manual = $this->customOrder(['status' => 'contracted', 'land_source_type' => 'project_lot', 're_project_lot_id' => $two->id, 'land_cost' => self::MANUAL, 'is_land_cost_manual' => true]);
        $this->saveOrder($manual, $this->fill($this->orderEditForm($manual), ['is_land_cost_manual' => '0']));
        $this->assertSame(9600000, $manual->fresh()->land_cost, '契約済みの注文住宅で手入力をやめたのに前の値が残った');

        $automatic = $this->customOrder(['order_code' => 'CO-002', 'status' => 'contracted', 'land_source_type' => 'project_lot', 're_project_lot_id' => $two->id, 'land_cost' => self::MANUAL, 'is_land_cost_manual' => false]);
        $this->saveOrder($automatic, $this->fill($this->orderEditForm($automatic), ['notes' => '外構は別途', 'land_cost' => '7000000']));
        $automatic->refresh();
        $this->assertSame([self::MANUAL, '外構は別途'], [$automatic->land_cost, $automatic->notes], '契約済みの注文住宅で備考だけ直したのに土地原価が動いた');
    }

    public function test_a_procurement_order_computes_from_its_costs(): void
    {
        $procurement = $this->costedProcurement();
        $order = $this->customOrder(['land_source_type' => 'procurement', 're_procurement_id' => $procurement->id, 'land_cost' => self::MANUAL, 'is_land_cost_manual' => true]);

        $this->saveOrder($order, $this->fill($this->orderEditForm($order), ['is_land_cost_manual' => '0']));

        $this->assertSame(7850000, $order->fresh()->land_cost);
    }

    public function test_a_customer_land_order_stays_empty_even_with_refresh(): void
    {
        $order = $this->customOrder(['land_source_type' => 'customer_land']);
        $form = $this->orderEditForm($order);
        $form['fields']['refresh_land_cost'] = '1';

        $this->saveOrder($order, $form);

        $order->refresh();
        $this->assertSame([null, false], [$order->land_cost, $order->is_land_cost_manual]);
    }

    // ============================================================
    // 契約一覧から開く契約の編集（建売・注文住宅）
    // ============================================================

    private function buildingContractForm(HsContract $contract, string $js = ''): array
    {
        $html = $this->htmlOf(route('housing.contracts.edit-building', $contract));
        $action = route('housing.contracts.update-building', $contract);
        preg_match('/action="' . preg_quote($action, '/') . '"[^>]*\bx-data="(\{[^"]*\})"/', $html, $m);
        $this->assertNotEmpty($m, '契約の編集のフォームが見つからない');

        return $this->composedForm($html, $action, null, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), ['datePicker' => '', 'buyerSelect' => ''], $js);
    }

    private function contractOn(bool $manual): array
    {
        [, $two] = $this->pricedProject('sold');
        $property = $this->propertyOnLot($manual, $two, ['building_cost' => 15000000]);

        return [$property, $this->contract($property, $this->buyer())];
    }

    public function test_the_building_contract_edit_follows_the_same_rules(): void
    {
        [$property, $contract] = $this->contractOn(true);
        $url = route('housing.contracts.edit-building', $contract);
        $html = $this->landed($this->submit($this->buildingContractForm($contract, 'data.isLandCostManual = false;'), $url));
        $this->assertFlash($html, 'success', '契約を更新しました。');
        $property->refresh();
        $this->assertSame([9600000, false], [$property->land_cost, $property->is_land_cost_manual], '契約の編集で手入力をやめたのに前の値が残った（⑤）');

        // 手動 OFF のまま備考だけ → 動かない／「今の計算値にする」→ 計算値
        $property->update(['land_cost' => self::MANUAL]);
        $this->landed($this->submit($this->fill($this->buildingContractForm($contract), ['notes' => '鍵は 2 本']), $url));
        $this->assertSame(self::MANUAL, $property->fresh()->land_cost, '契約の編集で備考だけ直したのに土地原価が動いた');

        $form = $this->buildingContractForm($contract);
        $form['fields']['refresh_land_cost'] = '1';
        $this->landed($this->submit($form, $url));
        $this->assertSame(9600000, $property->fresh()->land_cost, '「今の計算値にする」で計算値にならない');
    }

    private function customOrderContractForm(HsCustomOrder $order, string $js = ''): array
    {
        return $this->composedForm($this->htmlOf(route('housing.contracts.edit-custom-order', $order)), route('housing.contracts.update-custom-order', $order),
            'customOrderEditForm', null, ['datePicker' => '', 'buyerSelect' => ''], $js);
    }

    public function test_the_custom_order_contract_edit_follows_the_same_rules(): void
    {
        [, $two] = $this->pricedProject('sold');
        $order = $this->customOrder([
            'status' => 'contracted', 'contract_date' => '2026-09-10', 'customer_id' => $this->buyer()->id,
            'building_contract_price' => 25000000, 'building_cost' => 20000000,
            'land_source_type' => 'project_lot', 're_project_lot_id' => $two->id,
            'land_cost' => self::MANUAL, 'is_land_cost_manual' => true,
        ]);
        $url = route('housing.contracts.edit-custom-order', $order);

        $html = $this->landed($this->submit($this->customOrderContractForm($order, 'data.isLandCostManual = false;'), $url));
        $this->assertFlash($html, 'success', '契約を更新しました。');
        $order->refresh();
        $this->assertSame([9600000, false], [$order->land_cost, $order->is_land_cost_manual], '契約の編集で手入力をやめたのに前の値が残った（⑤）');

        $order->update(['land_cost' => self::MANUAL]);
        $this->landed($this->submit($this->fill($this->customOrderContractForm($order), ['notes' => '鍵は 2 本']), $url));
        $this->assertSame(self::MANUAL, $order->fresh()->land_cost, '契約の編集で備考だけ直したのに土地原価が動いた');

        $form = $this->customOrderContractForm($order);
        $form['fields']['refresh_land_cost'] = '1';
        $this->landed($this->submit($form, $url));
        $this->assertSame(9600000, $order->fresh()->land_cost, '「今の計算値にする」で計算値にならない');
    }
}
