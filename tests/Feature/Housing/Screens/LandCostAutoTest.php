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
        $form = $this->fill($this->propertyEditForm($property), ['notes' => '外構は別途']);
        $form['fields']['land_cost'] = '7000000'; // 手動 OFF の欄は送らない画面だが、古い画面・手で組んだ送信の値も使わない

        $this->saveProperty($property, $form);

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
        $form = $this->propertyEditForm($property, 'data.isLandCostManual = true; data.landCost = "6000000";');
        $this->assertSame(['1', '6000000'], [$form['fields']['is_land_cost_manual'], $form['fields']['land_cost'] ?? null]);
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
        $form = $this->fill($form, ['property_name' => '平井 建売 2', 'is_land_cost_manual' => '0']);
        $form['fields']['land_cost'] = '7000000';

        $this->landed($this->submit($form, $url));

        $this->assertSame(9600000, HsProperty::where('property_name', '平井 建売 2')->firstOrFail()->land_cost, '新規登録で送られた値を保存した');

        // 紐付けの無い建売は計算できない（空）
        $form = $this->fill($this->browserForm($this->htmlOf($url), $needle, 'housingPropertyForm'),
            ['property_name' => '紐付けなし', 'address' => '愛媛県松山市1', 'is_land_cost_manual' => '0']);
        $form['fields']['land_cost'] = '7000000';
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

        $form['fields']['land_cost'] = '7000000';
        $this->saveOrder($order, $form);

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
        $form = $this->fill($this->orderEditForm($automatic), ['notes' => '外構は別途']);
        $form['fields']['land_cost'] = '7000000';
        $this->saveOrder($automatic, $form);
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
    // 登録・編集の画面（建売・注文住宅の _form）: 保存される値を出す
    // ============================================================

    private const NOTE_COMPUTE = '保存すると、紐付け先から計算したこの値になります。';
    private const NOTE_UNAVAILABLE = '紐付け先から計算できません（分譲地の全区画に販売価格が入っていない／仕入れ案件に原価が無い）。このまま保存すると空になります。';

    /**
     * 画面の JS を動かし、土地原価の表示に使う値を読む。
     * view = housingLandCostView() の結果（willCompute・value・refreshOffered・unavailable）、shown = 手動 OFF の欄に出る文字。
     */
    private function landCostScreen(string $html, string $function, string $steps = '', array $responses = []): array
    {
        $run = $this->driveAlpine($html, $function, $this->xData($html, $function), $steps, $responses, true,
            ['landCostView()', 'landCostShownText()', 'housingYen(computedLandCost)']);

        return ['view' => $run['evaluated'][0], 'shown' => $run['evaluated'][1], 'yen' => $run['evaluated'][2]];
    }

    public function test_unchecking_manual_shows_and_saves_the_computed_value(): void
    {
        $property = $this->propertyOnLot(true);
        $html = $this->htmlOf(route('housing.properties.edit', $property));
        $this->assertTrue(str_contains($html, self::NOTE_COMPUTE), '計算し直すことを知らせる文が無い');

        $screen = $this->landCostScreen($html, 'housingPropertyForm', 'data.isLandCostManual = false;');
        $this->assertSame([true, 9600000, '9,600,000'], [$screen['view']['willCompute'], $screen['view']['value'], $screen['shown']], 'チェックを外しても計算値に替わらない');

        $form = $this->propertyEditForm($property, 'data.isLandCostManual = false;');
        $this->assertArrayNotHasKey('land_cost', $form['fields'], '手動 OFF の欄の値を送っている');
        $this->saveProperty($property, $form);
        $this->assertSame(9600000, $property->fresh()->land_cost, '画面に出した値と保存した値が違う');
    }

    public function test_a_stale_automatic_value_offers_the_refresh(): void
    {
        $property = $this->propertyOnLot(false);
        $html = $this->htmlOf(route('housing.properties.edit', $property));
        $this->assertTrue(str_contains($html, 'name="refresh_land_cost"'), '「今の計算値にする」のチェックが無い');

        $screen = $this->landCostScreen($html, 'housingPropertyForm');
        $this->assertSame([false, 5000000, true, '9,600,000円'], [$screen['view']['willCompute'], $screen['view']['value'], $screen['view']['refreshOffered'], $screen['yen']]);

        $this->saveProperty($property, $this->propertyEditForm($property));
        $this->assertSame(self::MANUAL, $property->fresh()->land_cost, 'チェックを付けないのに値が動いた');

        $screen = $this->landCostScreen($html, 'housingPropertyForm', 'data.refreshLandCost = true;');
        $this->assertSame([true, 9600000, true], [$screen['view']['willCompute'], $screen['view']['value'], $screen['view']['refreshOffered']], 'チェックを付けたら外せるように出し続ける');
        $form = $this->propertyEditForm($property, 'data.refreshLandCost = true;');
        $this->assertSame('1', $form['fields']['refresh_land_cost'] ?? null);
        $this->saveProperty($property, $form);
        $this->assertSame(9600000, $property->fresh()->land_cost, '「今の計算値にする」を画面から送っても計算値にならない');
    }

    /**
     * 「今の計算値（Y円）にする」は 1 つの文として流れる（ラベルは flex なので、文字と金額が別々の項目だと
     * 狭い画面で「今の / 計算 / 値（」と縦に割れる。2026-11-20 に 375px の契約の編集で実測）。
     */
    public function test_the_refresh_label_reads_as_one_run_of_text(): void
    {
        $property = $this->propertyOnLot(false);
        [, $contract] = $this->contractOn(false);
        $run = '<span>今の計算値（<span x-text="housingYen(computedLandCost)"></span>）にする</span>';

        foreach ([route('housing.properties.edit', $property), route('housing.contracts.edit-building', $contract)] as $url) {
            $this->assertTrue(str_contains($this->htmlOf($url), $run), "{$url}: 「今の計算値にする」の文が 1 つの span にまとまっていない");
        }
    }

    /**
     * 紐付けの無い行で手動入力を外しても、「紐付け先から計算したこの値になります」と言わない（値は空になる。最後の点検の指摘）。
     * 紐付けのある行では今どおり出す（直しすぎを止める）。
     */
    public function test_an_unlinked_row_does_not_claim_a_computed_value(): void
    {
        $note = function (string $html): string {
            $this->assertSame(1, preg_match('/<p x-show="([^"]*)"[^>]*>' . preg_quote(self::NOTE_COMPUTE, '/') . '<\/p>/u', $html, $m), '「計算する」の文が無い');

            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        };
        $shown = function (string $html, string $function) use ($note): bool {
            return $this->driveAlpine($html, $function, $this->xData($html, $function), 'data.isLandCostManual = false;', [], true, [$note($html)])['evaluated'][0];
        };

        $unlinked = $this->property(['land_cost' => self::MANUAL, 'is_land_cost_manual' => true]);
        $this->assertFalse($shown($this->htmlOf(route('housing.properties.edit', $unlinked)), 'housingPropertyForm'), '紐付けが無いのに「計算したこの値になります」と出した');
        $contract = $this->contract($unlinked, $this->buyer());
        $this->assertFalse($shown($this->htmlOf(route('housing.contracts.edit-building', $contract)), 'buildingContractCostForm'), '契約の編集: 紐付けが無いのに「計算したこの値になります」と出した');

        $linked = $this->propertyOnLot(true, null, ['property_code' => 'HS-002', 'property_name' => '平井 建売 2']);
        $this->assertTrue($shown($this->htmlOf(route('housing.properties.edit', $linked)), 'housingPropertyForm'), '紐付けがあるのに「計算したこの値になります」を出さない');
    }

    /**
     * 土地原価の表示の決まりで出し分ける要素は、Alpine が動くまで隠れている（style="display: none;"。契約の編集の画面の流儀。
     * 無いと「このまま保存すると空になります」などが読み込みのあいだ一瞬見える。最後の点検の指摘）。
     */
    public function test_the_land_cost_messages_start_hidden(): void
    {
        [$property, $contract] = $this->contractOn(false);
        $screens = [
            route('housing.properties.edit', $property) => 3,
            route('housing.contracts.edit-building', $contract) => 6,
        ];
        foreach ($screens as $url => $minimum) {
            preg_match_all('/<[a-z]+\b[^>]*\bx-show="([^"]*)"[^>]*>/u', $this->htmlOf($url), $tags, PREG_SET_ORDER);
            $driven = array_filter($tags, fn (array $t) => preg_match('/landCostView\(|landCostLinkChanged\(/', html_entity_decode($t[1], ENT_QUOTES, 'UTF-8')));
            $this->assertGreaterThanOrEqual($minimum, count($driven), "{$url}: 表示の決まりで出し分ける要素を拾えていない");
            foreach ($driven as $t) {
                $this->assertTrue(str_contains($t[0], 'style="display: none;'), "{$url}: Alpine が動く前から見えている: {$t[0]}");
            }
        }
    }

    public function test_an_equal_value_offers_no_refresh(): void
    {
        $property = $this->propertyOnLot(false, null, ['land_cost' => 9600000]);

        $screen = $this->landCostScreen($this->htmlOf(route('housing.properties.edit', $property)), 'housingPropertyForm');

        $this->assertFalse($screen['view']['refreshOffered'], '保存値と計算値が同じなのに「今の計算値にする」を出した');
        $this->assertArrayNotHasKey('refresh_land_cost', $this->propertyEditForm($property)['fields']);
    }

    public function test_an_uncomputable_link_says_so(): void
    {
        [$project, $two] = $this->pricedProject();
        ReProjectLot::create(['project_id' => $project->id, 'lot_number' => 6, 'area_sqm' => 200, 'area_tsubo' => 60.5, 'selling_price' => null, 'status' => 'on_sale']);
        $property = $this->propertyOnLot(true, $two);
        $html = $this->htmlOf(route('housing.properties.edit', $property));
        $this->assertTrue(str_contains($html, self::NOTE_UNAVAILABLE), '計算できないことを知らせる文が無い');

        $screen = $this->landCostScreen($html, 'housingPropertyForm', 'data.isLandCostManual = false;');
        $this->assertSame([true, null, ''], [$screen['view']['unavailable'], $screen['view']['value'], $screen['shown']]);

        // 紐付けの無い新規登録では出さない（Review Focus 5）
        $screen = $this->landCostScreen($this->htmlOf(route('housing.properties.create')), 'housingPropertyForm');
        $this->assertSame([false, null], [$screen['view']['unavailable'], $screen['view']['value']]);
    }

    public function test_choosing_a_link_on_the_create_screen_shows_its_value(): void
    {
        [$project, , $four] = $this->pricedProject();
        $html = $this->htmlOf(route('housing.properties.create'));

        $screen = $this->landCostScreen($html, 'housingPropertyForm',
            'data.landSourceType = "project_lot"; data.onSourceTypeChange(); data.selectedProjectId = "' . $project->id . '"; data.onProjectChange();
             setImmediate(function () { data.selectedLotId = "' . $four->id . '"; data.onLotChange(); });',
            [$this->apiResponse(route('api.housing.project-lots', ['project_id' => $project->id, 'exclude_hs' => 1]))]);
        $this->assertSame([14400000, '14,400,000'], [$screen['view']['value'], $screen['shown']], '区画を選んでも計算値が出ない');

        $procurement = $this->costedProcurement();
        $bare = $this->procurement(['procurement_code' => 'RE-PRC-002']);
        foreach ([[$procurement, 7850000, false], [$bare, null, true]] as [$chosen, $value, $unavailable]) {
            $screen = $this->landCostScreen($html, 'housingPropertyForm',
                'data.landSourceType = "procurement"; data.onSourceTypeChange(); data.selectedProcurementId = "' . $chosen->id . '"; data.onProcurementChange();',
                [$this->apiResponse(route('api.housing.procurement-info', $chosen))]);
            $this->assertSame([$value, $unavailable], [$screen['view']['value'], $screen['view']['unavailable']], $chosen->procurement_code . ' を選んだときの値');
        }
    }

    public function test_typing_then_unchecking_keeps_the_saved_value(): void
    {
        $property = $this->propertyOnLot(false);
        $steps = 'data.isLandCostManual = true; data.landCost = "7000000"; data.isLandCostManual = false;';

        $screen = $this->landCostScreen($this->htmlOf(route('housing.properties.edit', $property)), 'housingPropertyForm', $steps);
        $this->assertSame([5000000, '5,000,000'], [$screen['view']['value'], $screen['shown']], '打ってから外した値が出た（⑤ の別の形）');

        $this->saveProperty($property, $this->propertyEditForm($property, $steps));
        $this->assertSame(self::MANUAL, $property->fresh()->land_cost, '打ってから外した値を保存した（⑤ の別の形）');
    }

    public function test_the_choice_survives_an_input_error(): void
    {
        // 「今の計算値にする」は old() で戻す
        $property = $this->propertyOnLot(false);
        $form = $this->fill($this->propertyEditForm($property, 'data.refreshLandCost = true;'), ['property_name' => '']);
        $back = $this->landed($this->submit($form, route('housing.properties.edit', $property)));
        $screen = $this->landCostScreen($back, 'housingPropertyForm');
        $this->assertSame([true, 9600000], [$screen['view']['willCompute'], $screen['view']['value']], '入力エラーで戻ると「今の計算値にする」が外れた');
        $this->assertSame(self::MANUAL, $property->fresh()->land_cost);

        // 「保存済みが手動 ON か」は DB の値で決める（戻った画面の手動入力は送った OFF）
        $manual = $this->propertyOnLot(true, null, ['property_code' => 'HS-002', 'property_name' => '平井 建売 2']);
        $form = $this->fill($this->propertyEditForm($manual, 'data.isLandCostManual = false;'), ['property_name' => '']);
        $back = $this->landed($this->submit($form, route('housing.properties.edit', $manual)));
        $screen = $this->landCostScreen($back, 'housingPropertyForm');
        $this->assertSame([true, 9600000], [$screen['view']['willCompute'], $screen['view']['value']], '戻った画面で、手入力をやめたことを忘れた');
    }

    public function test_the_order_form_shows_the_same(): void
    {
        [, $two] = $this->pricedProject('sold');
        $manual = $this->customOrder(['status' => 'contracted', 'land_source_type' => 'project_lot', 're_project_lot_id' => $two->id, 'land_cost' => self::MANUAL, 'is_land_cost_manual' => true]);
        $screen = $this->landCostScreen($this->htmlOf(route('housing.custom-orders.edit', $manual)), 'customOrderForm', 'data.isLandCostManual = false;');
        $this->assertSame([true, 9600000, '9,600,000'], [$screen['view']['willCompute'], $screen['view']['value'], $screen['shown']]);
        $this->saveOrder($manual, $this->orderEditForm($manual, 'data.isLandCostManual = false;'));
        $this->assertSame(9600000, $manual->fresh()->land_cost);

        $automatic = $this->customOrder(['order_code' => 'CO-002', 'status' => 'contracted', 'land_source_type' => 'project_lot', 're_project_lot_id' => $two->id, 'land_cost' => self::MANUAL, 'is_land_cost_manual' => false]);
        $html = $this->htmlOf(route('housing.custom-orders.edit', $automatic));
        $this->assertTrue(str_contains($html, 'name="refresh_land_cost"'));
        $screen = $this->landCostScreen($html, 'customOrderForm');
        $this->assertSame([5000000, true, '9,600,000円'], [$screen['view']['value'], $screen['view']['refreshOffered'], $screen['yen']]);
        $this->saveOrder($automatic, $this->orderEditForm($automatic, 'data.refreshLandCost = true;'));
        $this->assertSame(9600000, $automatic->fresh()->land_cost);
    }

    // ============================================================
    // 契約一覧から開く契約の編集（建売・注文住宅）
    // ============================================================

    private function buildingContractForm(HsContract $contract, string $js = ''): array
    {
        return $this->composedForm($this->htmlOf(route('housing.contracts.edit-building', $contract)), route('housing.contracts.update-building', $contract),
            'buildingContractCostForm', null, ['datePicker' => '', 'buyerSelect' => ''], $js);
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

    // ============================================================
    // 契約の編集の画面: 保存される値と今の計算値を出す
    // ============================================================

    /** 契約の編集の 2 画面（建売・注文住宅）。[画面の HTML, x-data の関数名, フォームを組む, 保存した行を読む, 紐付け先の表示名] */
    private function contractScreens(bool $manual, int $landCost): array
    {
        [$property, $contract] = $this->contractOn($manual);
        $property->update(['land_cost' => $landCost]);
        $two = $property->re_project_lot_id;
        $order = $this->customOrder([
            'status' => 'contracted', 'contract_date' => '2026-09-10', 'customer_id' => $this->buyer('佐藤', '花子')->id,
            'building_contract_price' => 25000000, 'building_cost' => 20000000,
            'land_source_type' => 'project_lot', 're_project_lot_id' => $two, 'land_cost' => $landCost, 'is_land_cost_manual' => $manual,
        ]);

        return [
            'building' => [
                'url' => route('housing.contracts.edit-building', $contract), 'function' => 'buildingContractCostForm',
                'form' => fn (string $js = '') => $this->buildingContractForm($contract, $js), 'saved' => fn () => $property->fresh()->land_cost,
                'label' => $property->fresh()->getLandSourceDisplay(),
            ],
            'custom' => [
                'url' => route('housing.contracts.edit-custom-order', $order), 'function' => 'customOrderEditForm',
                'form' => fn (string $js = '') => $this->customOrderContractForm($order, $js), 'saved' => fn () => $order->fresh()->land_cost,
                'label' => $order->fresh()->getLandSourceDisplay(),
            ],
        ];
    }

    public function test_the_contract_edit_shows_the_value_it_will_save(): void
    {
        foreach ($this->contractScreens(false, 9600000) as $name => $s) {
            $html = $this->htmlOf($s['url']);
            $this->assertTrue(str_contains($html, e('（' . $s['label'] . 'から取得した値）')), "{$name}: 保存されている値の出どころの書き方が無い");
            $this->assertFalse(str_contains($html, 'から自動参照'), "{$name}: 保存されている値を「自動参照」と出している");

            $screen = $this->landCostScreen($html, $s['function']);
            $this->assertSame([false, 9600000, '9,600,000円', false], [$screen['view']['willCompute'], $screen['view']['value'], $screen['shown'], $screen['view']['refreshOffered']], $name);
        }
    }

    public function test_unchecking_manual_on_the_contract_edit_shows_the_computed_value(): void
    {
        foreach ($this->contractScreens(true, self::MANUAL) as $name => $s) {
            $html = $this->htmlOf($s['url']);
            $this->assertTrue(str_contains($html, self::NOTE_COMPUTE), "{$name}: 計算し直すことを知らせる文が無い");

            $screen = $this->landCostScreen($html, $s['function'], 'data.isLandCostManual = false;');
            $this->assertSame([true, 9600000, '9,600,000円'], [$screen['view']['willCompute'], $screen['view']['value'], $screen['shown']], $name);

            $this->landed($this->submit(($s['form'])('data.isLandCostManual = false;'), $s['url']));
            $this->assertSame(9600000, ($s['saved'])(), "{$name}: 画面に出した値と保存した値が違う");
        }
    }

    public function test_the_contract_edit_offers_the_refresh(): void
    {
        foreach ($this->contractScreens(false, self::MANUAL) as $name => $s) {
            $html = $this->htmlOf($s['url']);
            $this->assertTrue(str_contains($html, 'name="refresh_land_cost"'), "{$name}: 「今の計算値にする」のチェックが無い");
            $screen = $this->landCostScreen($html, $s['function']);
            $this->assertSame([5000000, true, '9,600,000円'], [$screen['view']['value'], $screen['view']['refreshOffered'], $screen['yen']], $name);

            $this->landed($this->submit($this->fill(($s['form'])(), ['notes' => '鍵は 2 本']), $s['url']));
            $this->assertSame(self::MANUAL, ($s['saved'])(), "{$name}: チェックを付けないのに値が動いた");

            $form = ($s['form'])('data.refreshLandCost = true;');
            $this->assertSame('1', $form['fields']['refresh_land_cost'] ?? null, $name);
            $this->landed($this->submit($form, $s['url']));
            $this->assertSame(9600000, ($s['saved'])(), "{$name}: 「今の計算値にする」を画面から送っても計算値にならない");
        }
    }

    public function test_the_custom_order_contract_edit_explains_a_new_link(): void
    {
        [, $two, $four] = $this->pricedProject();
        $order = $this->customOrder([
            'contract_date' => '2026-09-10', 'customer_id' => $this->buyer()->id, 'building_contract_price' => 25000000, 'building_cost' => 20000000,
            'land_source_type' => 'project_lot', 're_project_lot_id' => $two->id, 'land_cost' => 9600000, 'is_land_cost_manual' => false,
        ]);
        $html = $this->htmlOf(route('housing.contracts.edit-custom-order', $order));
        $this->assertTrue(str_contains($html, '保存すると、新しい紐付け先から計算します。'));

        $run = $this->driveAlpine($html, 'customOrderEditForm', $this->xData($html, 'customOrderEditForm'), 'data.reProjectLotId = "' . $four->id . '";', [], true,
            ['landCostLinkChanged()', 'landCostShownText()', 'landCostView().unavailable']);
        $this->assertSame([true, '', false], $run['evaluated'], '紐付け先を変えたのに前の紐付け先の金額を出した');

        $this->landed($this->submit($this->customOrderContractForm($order, 'data.reProjectLotId = "' . $four->id . '";'), route('housing.contracts.edit-custom-order', $order)));
        $order->refresh();
        $this->assertSame([$four->id, 14400000], [$order->re_project_lot_id, $order->land_cost]);
    }
}
