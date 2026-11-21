<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\HsContract;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;

/**
 * 建売（登録・編集・建売の契約・詳細）で土地の仕入れ案件の区画を選ぶ（設計書 §5.3〜§5.6）。
 *
 * - 区画のある土地を選ぶと区画の選択欄が出て、区画は必須（区画の無い保存済みの建売＝#21 の形は、何も変えずに保存できる）
 * - 建売は契約の前から区画を取る（1 つの区画に付けられる建売・契約・契約以降の注文住宅は 1 件だけ）
 * - 区画を選ぶと、土地面積に区画の面積・土地原価の自動の値に按分した額が入る（決めたこと 1）
 * - 編集画面の土地は今どおり読み取り専用（区画のラベルと hidden。決めたこと 2）
 * - 建売の契約の登録・削除は、その建売の区画だけを動かし、仕入れ案件のステータスを区画から集約する
 * - 建売の契約の土地販売価格の初期値は区画の販売価格（区画の販売価格が空なら空。決めたこと 1）
 *
 * ⚠ 送る値は描いた画面から取る（Bug #47）。区画の一覧は、登録画面では仕入れ案件を選んだときに API（procurement-info）から取り、
 *   入力エラーで戻った画面・編集画面はサーバが最初から渡す（決めたこと 8）。
 * ⚠ 土台の土地: 原価の行 30,000,000円・区画 1（132.69㎡・20,000,000円）・区画 2（100.50㎡・10,000,000円）
 *   → 按分は 20,000,000 / 10,000,000
 */
class ProcurementLotPropertyTest extends HousingScreenTestCase
{
    private const NOTE_UNAVAILABLE = '紐付け先から計算できません（分譲地・土地の全区画に販売価格が入っていない／仕入れ案件に原価が無い）。このまま保存すると空になります。';

    /** @return array{0: ReProcurement, 1: ReProcurementLot, 2: ReProcurementLot} */
    private function twoLotLand(?int $secondPrice = 10000000, array $overrides = []): array
    {
        $land = $this->landProcurement($overrides);
        $first = $this->procurementLot($land, ['lot_number' => 1, 'area_sqm' => 132.69, 'selling_price' => 20000000]);
        $second = $this->procurementLot($land, ['lot_number' => 2, 'area_sqm' => 100.5, 'selling_price' => $secondPrice]);

        return [$land, $first, $second];
    }

    /** その土地の区画に建つ建売（区画が null なら区画の無い建売＝#21 の形） */
    private function propertyOn(ReProcurement $land, ?ReProcurementLot $lot, array $overrides = []): HsProperty
    {
        return $this->property(array_merge([
            'land_source_type' => 'procurement',
            're_procurement_id' => $land->id,
            're_procurement_lot_id' => $lot?->id,
        ], $overrides));
    }

    private function createUrl(): string
    {
        return route('housing.properties.create');
    }

    private function storeNeedle(): string
    {
        return 'action="' . route('housing.properties.store') . '"';
    }

    private function selectProcurement(ReProcurement $land): string
    {
        return 'data.landSourceType = "procurement"; data.onSourceTypeChange(); data.selectedProcurementId = "' . $land->id . '"; data.onProcurementChange();';
    }

    /** 区画を選ぶ（API の応答が返ったあと＝区画の一覧が届いたあとで選ぶ） */
    private function chooseLot(ReProcurementLot $lot): string
    {
        return ' setImmediate(function () { data.selectedProcurementLotId = "' . $lot->id . '"; data.onProcurementLotChange(); });';
    }

    /**
     * 登録画面で土地元を仕入れ案件にし、$land を選んだあとのフォーム（区画の一覧は JS が API から取る。その応答をそのまま返す）。
     * ⚠ API の応答はここで作る（あとで区画が取られても、この画面は開いたときの一覧のまま＝古い画面）。
     */
    private function createForm(string $html, ReProcurement $land, string $lotSteps = ''): array
    {
        return $this->browserForm($html, $this->storeNeedle(), 'housingPropertyForm', $this->selectProcurement($land) . $lotSteps,
            [$this->apiResponse(route('api.housing.procurement-info', $land))]);
    }

    private function editForm(HsProperty $property, string $steps = ''): array
    {
        return $this->browserForm($this->htmlOf(route('housing.properties.edit', $property)),
            'action="' . route('housing.properties.update', $property) . '"', 'housingPropertyForm', $steps);
    }

    /** 区画の選択欄の開始タグ */
    private function lotSelectTag(string $html): string
    {
        $this->assertSame(1, preg_match('/<select name="re_procurement_lot_id"[^>]*>/u', $html, $m), '区画の選択欄が無い');

        return $m[0];
    }

    // ============================================================
    // 登録画面の区画の選択欄
    // ============================================================

    public function test_the_create_screen_shows_the_lot_select_for_a_land_with_lots(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $html = $this->htmlOf($this->createUrl());

        $form = $this->createForm($html, $land);
        $this->assertSame(route('api.housing.procurement-info', $land), $form['run']['requests'][0]['url']);
        $this->assertSame([$first->optionLabel(), $second->optionLabel()], array_column($form['run']['state']['procurementLots'], 'label'), '区画の選択肢が出ていない');
        $this->assertSame('', $form['fields']['re_procurement_lot_id'], '選べる区画が 2 つなのに区画が選ばれている');

        // 区画の選択欄: 分譲地区画の選択欄と同じ作り（:selected・Top trap #3）・HTML の required は付けない（決めたこと 9）・
        // 区画の無い土地・土地元が仕入れ案件でないときは送らない
        $tag = $this->lotSelectTag($html);
        $this->assertStringNotContainsString('required', $tag, '区画の選択欄に required が付いている');
        $this->assertStringContainsString(':disabled="landSourceType !== \'procurement\' || procurementLots.length === 0"', $tag, '区画の選択欄が隠れたまま送られる');
        $this->assertSame(1, preg_match('/<option :value="lot\.id" :selected="String\(lot\.id\) === String\(selectedProcurementLotId\)" x-text="lot\.label"><\/option>/u', $html), '区画の選択肢に :selected が無い（Top trap #3）');

        // 選べる区画が 1 つなら、仕入れ案件を選んだときにそれを選ぶ（ほかの建売が区画 1 を取っている）
        $this->propertyOn($land, $first, ['property_code' => 'HS-001', 'property_name' => '先に建てた建売']);
        $form = $this->createForm($this->htmlOf($this->createUrl()), $land);
        $this->assertSame([$second->id], array_column($form['run']['state']['procurementLots'], 'id'), '取られた区画が選択肢に出ている');
        $this->assertSame([(string) $second->id, '100.5'], [$form['fields']['re_procurement_lot_id'], $form['fields']['land_area_sqm']], '選べる区画が 1 つなのに選ばれていない');

        // 区画の無い土地: 区画の欄は送らない（今どおり仕入れ案件を選ぶだけ）
        $lotless = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '区画なしの土地']);
        $form = $this->createForm($this->htmlOf($this->createUrl()), $lotless);
        $this->assertSame([], $form['run']['state']['procurementLots']);
        $this->assertArrayNotHasKey('re_procurement_lot_id', $form['fields'], '区画の無い土地で区画の欄が送られている');
    }

    public function test_choosing_a_lot_fills_the_land_area_and_the_land_cost(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $url = $this->createUrl();

        $form = $this->createForm($this->htmlOf($url), $land, $this->chooseLot($second));
        $this->assertSame([(string) $second->id, '100.5', 10000000],
            [$form['fields']['re_procurement_lot_id'], $form['fields']['land_area_sqm'], $form['run']['state']['computedLandCost']],
            '区画を選んでも土地面積・土地原価の自動の値が区画のものにならない');

        $html = $this->landed($this->submit($this->fill($form, ['property_name' => '勝山 建売']), $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を登録しました。');
        $property = HsProperty::sole();
        $this->assertSame([$land->id, $second->id, 10000000, '100.50'],
            [$property->re_procurement_id, $property->re_procurement_lot_id, $property->land_cost, $property->land_area_sqm]);
    }

    public function test_the_chosen_lot_survives_an_input_error(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $url = $this->createUrl();
        $form = $this->createForm($this->htmlOf($url), $land, $this->chooseLot($second));

        // 物件名を書き忘れて送る
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '物件名']));
        // 戻った画面をそのまま送り直す（区画は選び直さない。区画の一覧はサーバが描いて渡す）
        $again = $this->browserForm($html, $this->storeNeedle(), 'housingPropertyForm');
        $this->assertSame([], $again['run']['requests'], '戻った画面が区画の一覧を API から取り直している');
        $this->assertSame([(string) $land->id, (string) $second->id], [$again['fields']['re_procurement_id'], $again['fields']['re_procurement_lot_id'] ?? null], '入力エラーで戻ると選んでいた区画が消える');

        $html = $this->landed($this->submit($this->fill($again, ['property_name' => '勝山 建売']), $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を登録しました。');
        $this->assertSame([$second->id, 10000000], [HsProperty::sole()->re_procurement_lot_id, HsProperty::sole()->land_cost]);
    }

    // ============================================================
    // 1 区画 1 件（建売は契約の前から区画を取る）
    // ============================================================

    public function test_a_taken_lot_is_refused_for_a_new_building(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $url = $this->createUrl();
        // 開いたあとでほかの人が区画 1 に建売を登録した画面（古い画面）から送る
        $stale = $this->fill($this->createForm($this->htmlOf($url), $land, $this->chooseLot($first)), ['property_name' => '後から来た建売']);
        $this->propertyOn($land, $first, ['property_code' => 'HS-001', 'property_name' => '先に建てた建売']);

        $html = $this->landed($this->submit($stale, $url));

        $this->assertErrorItem($html, 'この区画（1号地）は建売物件「HS-001 先に建てた建売」に紐づいています。ほかの区画を選んでください。');
        $this->assertSame(1, HsProperty::count());

        // 不動産の契約が取っている区画も同じ
        $contracted = $this->fill($this->createForm($this->htmlOf($url), $land, $this->chooseLot($second)), ['property_name' => '後から来た建売']);
        ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_land', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の土地', 'procurement_id' => $land->id, 'procurement_lot_id' => $second->id,
            'contract_amount_land' => 10000000, 'cost_amount' => 10000000, 'created_by' => $this->user->id,
        ]);
        $html = $this->landed($this->submit($contracted, $url));
        $this->assertErrorItem($html, 'この区画（2号地）は契約「勝山の土地」に紐づいています。ほかの区画を選んでください。');
        $this->assertSame(1, HsProperty::count());

        // 区画が空いていれば通る
        ReContract::query()->delete();
        $html = $this->landed($this->submit($this->fill($this->createForm($this->htmlOf($url), $land, $this->chooseLot($second)), ['property_name' => '後から来た建売']), $url));
        $this->assertFlash($html, 'success', '建売物件「HS-002」を登録しました。');
        $this->assertSame($second->id, HsProperty::where('property_code', 'HS-002')->sole()->re_procurement_lot_id);
    }

    /** Ruling 11: 取られた区画で断られて戻った画面は、一覧に無くなった区画を選んだままにしない（その区画の按分も出さない） */
    public function test_a_refused_lot_is_not_kept_on_the_returned_screen(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $url = $this->createUrl();
        $stale = $this->fill($this->createForm($this->htmlOf($url), $land, $this->chooseLot($first)), ['property_name' => '後から来た建売']);
        $this->propertyOn($land, $first, ['property_code' => 'HS-001', 'property_name' => '先に建てた建売']);

        $html = $this->landed($this->submit($stale, $url));

        $this->assertErrorItem($html, 'この区画（1号地）は建売物件「HS-001 先に建てた建売」に紐づいています。ほかの区画を選んでください。');
        // 戻った画面はそのまま組める（x-model の値が選択肢に無い＝ブラウザでは空で送られる形にしない）
        $again = $this->browserForm($html, $this->storeNeedle(), 'housingPropertyForm');
        $this->assertSame([], $again['run']['requests'], '戻った画面が区画の一覧を API から取り直している');
        $this->assertSame([$second->id], array_column($again['run']['state']['procurementLots'], 'id'));
        $this->assertSame([(string) $land->id, ''], [$again['fields']['re_procurement_id'], $again['fields']['re_procurement_lot_id'] ?? null], '戻った画面が取られた区画を選んだまま');
        $this->assertNull($again['run']['state']['computedLandCost'], '戻った画面が取られた区画の按分を出している');

        // 画面の JS も同じ（サーバが一覧に無い区画を渡しても、init() で外す）
        $tampered = str_replace("selectedProcurementLotId: ''", "selectedProcurementLotId: '" . $first->id . "'", $html);
        $this->assertNotSame($html, $tampered);
        $again = $this->browserForm($tampered, $this->storeNeedle(), 'housingPropertyForm');
        $this->assertSame('', $again['fields']['re_procurement_lot_id'] ?? null, '画面の JS が一覧に無い区画を選んだまま');

        // 選び直して保存できる
        $html = $this->landed($this->submit($this->browserForm($html, $this->storeNeedle(), 'housingPropertyForm',
            'data.selectedProcurementLotId = "' . $second->id . '"; data.onProcurementLotChange();'), $url));
        $this->assertFlash($html, 'success', '建売物件「HS-002」を登録しました。');
        $this->assertSame([$second->id, 10000000], [HsProperty::where('property_code', 'HS-002')->sole()->re_procurement_lot_id, HsProperty::where('property_code', 'HS-002')->sole()->land_cost]);
    }

    /** Review Focus 1: 手で組んだ送信で別の土地の区画・無い区画・配列を送る → 入力エラーで断り、何も保存しない */
    public function test_a_building_with_a_lot_of_another_land_is_refused(): void
    {
        [$land, $first] = $this->twoLotLand();
        $other = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $otherLot = $this->procurementLot($other);
        $url = $this->createUrl();
        $form = $this->fill($this->createForm($this->htmlOf($url), $land, $this->chooseLot($first)), ['property_name' => '勝山 建売']);

        foreach ([
            '別の土地の区画' => [(string) $otherLot->id, trans('validation.exists', ['attribute' => '区画'])],
            '存在しない区画' => ['999999', trans('validation.exists', ['attribute' => '区画'])],
            '配列' => [[(string) $first->id], trans('validation.integer', ['attribute' => '区画'])],
        ] as $case => [$value, $message]) {
            $html = $this->landed($this->submit($this->fill($form, ['re_procurement_lot_id' => $value]), $url));

            $this->assertErrorItem($html, $message);
            $this->assertSame(0, HsProperty::count(), "{$case}で建売ができた");
        }
    }

    public function test_a_lot_is_required_on_a_land_with_lots(): void
    {
        [$land] = $this->twoLotLand();
        $url = $this->createUrl();
        $form = $this->createForm($this->htmlOf($url), $land);
        $this->assertSame('', $form['fields']['re_procurement_lot_id']);

        $html = $this->landed($this->submit($this->fill($form, ['property_name' => '勝山 建売']), $url));

        $this->assertErrorItem($html, '区画を選んでください。');
        $this->assertSame(0, HsProperty::count());

        // 選択欄ごと送られない手で組んだ送信も断る
        $form = $this->fill($form, ['property_name' => '勝山 建売']);
        unset($form['fields']['re_procurement_lot_id']);
        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, '区画を選んでください。');
        $this->assertSame(0, HsProperty::count());
    }

    /** 全区画がほかに取られた土地は、登録画面の仕入れ案件の選択肢に出さない（選んでも区画を選べず、保存で断られるだけなので） */
    public function test_a_land_whose_lots_are_all_taken_is_not_offered(): void
    {
        $full = $this->landProcurement(['procurement_code' => 'RE-PRC-001', 'property_name' => '全部取られた土地']);
        $this->propertyOn($full, $this->procurementLot($full), ['property_code' => 'HS-001', 'property_name' => '先に建てた建売']);
        [$open] = $this->twoLotLand(10000000, ['procurement_code' => 'RE-PRC-002', 'property_name' => '空いている土地']);
        $lotless = $this->landProcurement(['procurement_code' => 'RE-PRC-003', 'property_name' => '区画なしの土地']);

        $run = $this->driveAlpine($this->htmlOf($this->createUrl()), 'housingPropertyForm', 'housingPropertyForm()', '', evaluate: ['data.procurements.map(function (p) { return p.id; })']);

        $this->assertSame([$lotless->id, $open->id], $run['evaluated'][0], '全区画が取られた土地が選択肢に出ている（区画のある土地・区画の無い土地は出る）');
    }

    // ============================================================
    // 編集画面（決めたこと 2: 土地は読み取り専用）
    // ============================================================

    public function test_the_edit_screen_keeps_the_lot_read_only(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $property = $this->propertyOn($land, $second, ['land_cost' => 5000000, 'is_land_cost_manual' => false]);
        $url = route('housing.properties.edit', $property);

        $html = $this->htmlOf($url);
        $this->assertSame(0, preg_match('/<select name="re_procurement_lot_id"/u', $html), '編集画面に区画の選択欄がある（土地は読み取り専用）');
        $this->assertSame(1, preg_match('/<input type="hidden" name="re_procurement_lot_id" value="' . $second->id . '">/u', $html), '編集画面に区画の hidden が無い');
        $this->assertStringContainsString('区画 2号地 100.50㎡（30.40坪） 販売価格 10,000,000円', $html);
        // 画面の JS も紐付けは変わっていないとみなす（保存済みの値 5,000,000 を出し、区画の按分 10,000,000 は「今の計算値にする」で選べる）
        $run = $this->driveAlpine($html, 'housingPropertyForm', 'housingPropertyForm()', '', evaluate: ['landCostView()']);
        $this->assertSame([false, 5000000, true], [$run['evaluated'][0]['willCompute'], $run['evaluated'][0]['value'], $run['evaluated'][0]['refreshOffered']],
            '画面の JS が区画を紐付けに含めず「紐付けが変わった」とみなしている（savedLink の procurementLot）');

        $form = $this->fill($this->editForm($property), ['notes' => '外構は別途']);
        $this->assertSame([(string) $land->id, (string) $second->id], [$form['fields']['re_procurement_id'], $form['fields']['re_procurement_lot_id']]);
        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $property->refresh();
        $this->assertSame([$second->id, 5000000, '外構は別途'], [$property->re_procurement_lot_id, $property->land_cost, $property->notes],
            '何も変えない保存で区画か土地原価が動いた（区画を渡さないと「紐付けが変わった」と見える）');

        // 契約のある建売（土地の紐付けを固定）も、何も変えずに保存できる
        $this->contract($property, $this->buyer());
        $html = $this->landed($this->submit($this->fill($this->editForm($property), ['notes' => '鍵は 2 本']), $url));
        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $this->assertSame([$second->id, '鍵は 2 本'], [$property->fresh()->re_procurement_lot_id, $property->fresh()->notes]);
    }

    /** 手で組んだ送信で土地元を分譲地区画に変えたら、土地の区画も外す（区画を取ったまま残さない＝ほかの建売・契約が選べるように） */
    public function test_changing_the_land_type_drops_the_lot(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $project = $this->project();
        $projectLot = $this->lot($project, 3);
        $property = $this->propertyOn($land, $second);
        $url = route('housing.properties.edit', $property);
        $form = $this->editForm($property);
        $form['fields']['land_source_type'] = 'project_lot';
        $form['fields']['re_project_lot_id'] = (string) $projectLot->id;

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $property->refresh();
        $this->assertSame(['project_lot', $projectLot->id, null, null],
            [$property->land_source_type->value, $property->re_project_lot_id, $property->re_procurement_id, $property->re_procurement_lot_id], '土地の区画が残った');
    }

    /** Review Focus 4: 区画の無い建売（#21 の形）の土地に区画を足しても、編集画面をそのまま保存できる */
    public function test_a_legacy_building_without_a_lot_saves_as_is(): void
    {
        $land = $this->landProcurement();
        $property = $this->propertyOn($land, null, ['land_cost' => 5000000, 'is_land_cost_manual' => false]);
        // あとから区画を足す（2 区画。区画の無い建売の按分は空になるが、紐付けを変えない保存では計算し直さない）
        $this->procurementLot($land, ['lot_number' => 1]);
        $this->procurementLot($land, ['lot_number' => 2, 'selling_price' => 10000000]);
        $url = route('housing.properties.edit', $property);

        $html = $this->htmlOf($url);
        $this->assertSame(1, preg_match('/<input type="hidden" name="re_procurement_lot_id" value="">/u', $html), '区画の無い建売の hidden が無い');
        $this->assertStringNotContainsString('区画 1号地', $html);

        $form = $this->fill($this->editForm($property), ['notes' => '外構は別途']);
        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $property->refresh();
        $this->assertSame([null, 5000000, '外構は別途'], [$property->re_procurement_lot_id, $property->land_cost, $property->notes]);

        // 契約のある区画の無い建売も同じ
        $this->contract($property, $this->buyer());
        $html = $this->landed($this->submit($this->fill($this->editForm($property), ['notes' => '鍵は 2 本']), $url));
        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $this->assertNull($property->fresh()->re_procurement_lot_id);
    }

    /** M2・Ruling 15: 食い違ったデータ（今の区画に不動産の契約も付いている。同時の保存でだけ起きる）でも、建売の何も変えない保存は断らない */
    public function test_an_unchanged_building_save_passes_even_if_the_lot_is_also_taken_elsewhere(): void
    {
        [$land, $first] = $this->twoLotLand();
        $property = $this->propertyOn($land, $first);
        ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_land', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の土地', 'procurement_id' => $land->id, 'procurement_lot_id' => $first->id,
            'contract_amount_land' => 20000000, 'cost_amount' => 20000000, 'created_by' => $this->user->id,
        ]);
        $url = route('housing.properties.edit', $property);

        $html = $this->landed($this->submit($this->fill($this->editForm($property), ['notes' => '外構は別途']), $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $this->assertSame([$first->id, '外構は別途'], [$property->fresh()->re_procurement_lot_id, $property->fresh()->notes]);
    }

    /** Ruling 15: 区画を付け替える（手で組んだ送信）とき、その区画が取られていれば今どおり断る（変えた区画は見る） */
    public function test_switching_a_building_to_a_taken_lot_is_still_refused(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $property = $this->propertyOn($land, $first);
        $this->propertyOn($land, $second, ['property_code' => 'HS-002', 'property_name' => '区画 2 の建売']);
        $url = route('housing.properties.edit', $property);

        $html = $this->landed($this->submit($this->fill($this->editForm($property), ['re_procurement_lot_id' => (string) $second->id]), $url));

        $this->assertErrorItem($html, 'この区画（2号地）は建売物件「HS-002 区画 2 の建売」に紐づいています。ほかの区画を選んでください。');
        $this->assertSame($first->id, $property->fresh()->re_procurement_lot_id);
    }

    // ============================================================
    // 建売の契約（区画のステータス・土地販売価格の初期値）
    // ============================================================

    private function registerContract(HsProperty $property): void
    {
        $buyer = $this->buyer(); // 画面を開く前に（買主の選択肢は画面に描かれる）
        $url = route('housing.contracts.create', $property);
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('housing.contracts.store', $property) . '"', 'buyerSelect', $this->chooseBuyer($buyer));
        $html = $this->landed($this->submit($this->fill($form, ['contract_date' => '2026-10-01', 'selling_price_building' => '20000000']), $url));
        $this->assertFlash($html, 'success', '契約を登録しました。物件のステータスが「成約」に更新されました。');
    }

    private function destroyContract(HsProperty $property): void
    {
        $show = route('housing.properties.show', $property);
        $form = $this->parseForm($this->htmlOf($show), 'action="' . route('housing.contracts.destroy', $property) . '"');
        $html = $this->landed($this->submit($form, $show));
        $this->assertFlash($html, 'success', '契約を削除しました。物件のステータスが「販売中」に戻りました。');
    }

    /** @return array<int, string> 区画のステータス・仕入れ案件のステータス */
    private function statuses(ReProcurement $land, ReProcurementLot ...$lots): array
    {
        return [...array_map(fn (ReProcurementLot $lot) => $lot->fresh()->status->value, $lots), $land->fresh()->status->value];
    }

    public function test_a_building_contract_moves_only_its_lot(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $a = $this->propertyOn($land, $first, ['property_code' => 'HS-001', 'property_name' => '区画 1 の建売']);
        $b = $this->propertyOn($land, $second, ['property_code' => 'HS-002', 'property_name' => '区画 2 の建売']);

        $this->registerContract($a);
        $this->assertSame(['sold', 'on_sale', 'selling'], $this->statuses($land, $first, $second), '区画 1 つの建売の契約で、ほかの区画か仕入れ案件が動いた');

        $this->registerContract($b);
        $this->assertSame(['sold', 'sold', 'sold'], $this->statuses($land, $first, $second), '全区画が成約なのに仕入れ案件が販売済にならない');

        $this->destroyContract($a);
        $this->assertSame(['on_sale', 'sold', 'selling'], $this->statuses($land, $first, $second));
        $this->assertSame(1, HsContract::count());
    }

    /**
     * 建売の契約で土地の全区画が売れて仕入れ案件が「販売済」になっても、その建売の編集画面はそのまま保存でき、選択肢に今の仕入れ案件が残る
     * （建売の編集画面の土地は読み取り専用。新しい建売には販売済の土地を出さない）
     */
    public function test_a_building_on_a_sold_out_land_saves_unchanged(): void
    {
        $land = $this->landProcurement();
        $lot = $this->procurementLot($land);
        $property = $this->propertyOn($land, $lot, ['land_cost' => 9600000, 'is_land_cost_manual' => false]);
        $this->registerContract($property);
        $this->assertSame(['sold', 'sold'], $this->statuses($land, $lot), 'テストの前提: 全区画が売れた土地が「販売済」になっていない');
        // 区画の無い「販売済」の土地（全区画が取られた土地の絞り込みでは外れない＝ステータスの絞り込みだけが外す）
        $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '区画なしの売れた土地', 'status' => 'sold']);
        $ids = ['data.procurements.map(function (p) { return p.id; })'];

        $run = $this->driveAlpine($this->htmlOf($this->createUrl()), 'housingPropertyForm', 'housingPropertyForm()', '', evaluate: $ids);
        $this->assertSame([], $run['evaluated'][0], '販売済の土地を新しい建売の選択肢に出している');
        $run = $this->driveAlpine($this->htmlOf(route('housing.properties.edit', $property)), 'housingPropertyForm', 'housingPropertyForm()', '', evaluate: $ids);
        $this->assertSame([$land->id], $run['evaluated'][0], '編集画面で今の仕入れ案件が選択肢から消えた');

        $html = $this->landed($this->submit($this->editForm($property), route('housing.properties.edit', $property)));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $property->refresh();
        $this->assertSame([$land->id, $lot->id, 9600000], [$property->re_procurement_id, $property->re_procurement_lot_id, $property->land_cost], 'そのまま保存で紐付けか土地原価が動いた');
    }

    /** 決めたこと 1: 建売の契約の土地販売価格の初期値は区画の販売価格（区画の販売価格が空なら空。土地全体の価格にしない） */
    public function test_the_contract_default_land_price_comes_from_the_lot(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $property = $this->propertyOn($land, $second, ['target_selling_price_building' => 21000000]);
        $url = route('housing.contracts.create', $property);
        $needle = 'action="' . route('housing.contracts.store', $property) . '"';

        $html = $this->htmlOf($url);
        $this->assertSame('10000000', $this->browserForm($html, $needle, 'buyerSelect')['fields']['selling_price_land'], '土地販売価格の初期値が区画の販売価格でない');
        $this->assertStringContainsString('土地販売価格 ← RE-PRC-001 勝山の土地 &gt; 2号地の販売価格', $html);

        $second->update(['selling_price' => null]);
        $this->assertSame('', $this->browserForm($this->htmlOf($url), $needle, 'buyerSelect')['fields']['selling_price_land'], '区画の販売価格が空なのに初期値が入った（土地全体の価格にしない）');

        // 区画の無い土地の建売は今どおり（想定販売価格（土地））
        $lotless = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '区画なしの土地']);
        $other = $this->propertyOn($lotless, null, ['property_code' => 'HS-002']);
        $this->assertSame('25000000', $this->browserForm($this->htmlOf(route('housing.contracts.create', $other)),
            'action="' . route('housing.contracts.store', $other) . '"', 'buyerSelect')['fields']['selling_price_land']);
    }

    /**
     * 契約一覧から開く建売の契約の編集: 土地原価の自動の値は建売の区画の按分（10,000,000）。何も変えない保存では計算し直さない
     * （区画を紐付けに渡さないと「紐付けが変わった」と見え、区画の無い按分＝空で上書きする）
     */
    public function test_the_building_contract_edit_uses_the_lot(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $property = $this->propertyOn($land, $second, ['land_cost' => 5000000, 'is_land_cost_manual' => false, 'building_cost' => 15000000]);
        $contract = $this->contract($property, $this->buyer());
        $url = route('housing.contracts.edit-building', $contract);
        $html = $this->htmlOf($url);
        $this->assertStringContainsString('RE-PRC-001 勝山の土地 &gt; 2号地から取得した値', $html);
        $form = fn (string $js = '') => $this->composedForm($this->htmlOf($url), route('housing.contracts.update-building', $contract),
            'buildingContractCostForm', null, ['datePicker' => '', 'buyerSelect' => ''], $js);

        $run = $this->driveAlpine($html, 'buildingContractCostForm', 'buildingContractCostForm()', '', evaluate: ['landCostView()', 'computedLandCost']);
        $this->assertSame([true, 10000000], [$run['evaluated'][0]['refreshOffered'], $run['evaluated'][1]], '区画の按分が「今の計算値」に出ていない');

        $html = $this->landed($this->submit($this->fill($form(), ['notes' => '鍵は 2 本']), $url));
        $this->assertFlash($html, 'success', '契約を更新しました。');
        $this->assertSame(5000000, $property->fresh()->land_cost, '何も変えない保存で土地原価が動いた');

        $this->landed($this->submit($form('data.refreshLandCost = true;'), $url));
        $this->assertSame([10000000, $second->id], [$property->fresh()->land_cost, $property->fresh()->re_procurement_lot_id], '「今の計算値にする」で区画の按分にならない');
    }

    // ============================================================
    // 土地原価の自動の値（Review Focus 5）
    // ============================================================

    /** Review Focus 5: 2 区画以上の土地で区画の 1 つに販売価格が無い → 土地原価の自動の値は空（「計算できません」の文が出て、保存すると空） */
    public function test_auto_land_cost_is_empty_when_a_lot_has_no_price(): void
    {
        [$land, $first, $second] = $this->twoLotLand(null);
        $url = $this->createUrl();
        $html = $this->htmlOf($url);
        $this->assertStringContainsString(self::NOTE_UNAVAILABLE, $html);
        $responses = [$this->apiResponse(route('api.housing.procurement-info', $land))];
        $evaluate = ['landCostView()'];

        // 区画を選ぶ前は「計算できません」を出さない（区画を選べば計算できるかもしれない。保存は区画が要ると断る）
        $run = $this->driveAlpine($html, 'housingPropertyForm', 'housingPropertyForm()', $this->selectProcurement($land), $responses, true, $evaluate);
        $this->assertSame([false, null], [$run['evaluated'][0]['unavailable'], $run['evaluated'][0]['value']], '区画を選ぶ前に「計算できません」が出た');

        $run = $this->driveAlpine($html, 'housingPropertyForm', 'housingPropertyForm()', $this->selectProcurement($land) . $this->chooseLot($first), $responses, true, $evaluate);
        $this->assertSame([true, null], [$run['evaluated'][0]['unavailable'], $run['evaluated'][0]['value']], '按分できない区画なのに「計算できません」が出ない');

        $form = $this->fill($this->createForm($html, $land, $this->chooseLot($first)), ['property_name' => '勝山 建売']);
        $this->landed($this->submit($form, $url));

        $property = HsProperty::sole();
        $this->assertSame([$first->id, null], [$property->re_procurement_lot_id, $property->land_cost], '按分できない区画で土地原価が入った');
    }

    // ============================================================
    // 詳細
    // ============================================================

    public function test_the_show_screen_shows_the_lot(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $property = $this->propertyOn($land, $second);

        $html = $this->htmlOf(route('housing.properties.show', $property));

        $this->assertStringContainsString('RE-PRC-001 勝山の土地 &gt; 2号地', $html);
        $this->assertStringContainsString('区画 2号地 100.50㎡（30.40坪）', $html);
        $this->assertStringNotContainsString('区画 1号地', $html, '建売の区画でなく土地の別の区画を出している');

        // 区画の無い建売（区画のある土地でも）は区画を出さない
        $legacy = $this->propertyOn($land, null, ['property_code' => 'HS-002']);
        $html = $this->htmlOf(route('housing.properties.show', $legacy));
        $this->assertSame(0, preg_match('/区画 \d+号地/u', $html), '区画の無い建売に区画を出している');
    }
}
