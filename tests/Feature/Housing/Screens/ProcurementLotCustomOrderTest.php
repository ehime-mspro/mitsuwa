<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\Buyer;
use App\Models\HsCustomOrder;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use App\Support\ProcurementLotClaims;

/**
 * 注文住宅（登録・編集・一覧のステータスの小窓・契約一覧から開く契約の編集・詳細）で土地の仕入れ案件の区画を選ぶ（設計書 §5.3〜§5.6）。
 *
 * - 区画のある土地を選ぶと区画の選択欄が出て、区画は必須（区画の無い保存済みの注文住宅＝#21 の形は、何も変えずに保存できる）
 * - 見積り以前の注文住宅は区画を取らない（同じ区画に何件でも付く）。「契約」以降に進むとき、区画がほかに取られていれば断る
 * - 見積り以前の注文住宅も、選べるのは「取られていない区画」と「今持っている区画」だけ（決めたこと 4）
 * - 区画を選ぶと、土地面積に区画の面積・土地原価の自動の値に按分した額が入る（決めたこと 1）
 * - 「契約」をまたぐ・契約以降で削除すると、その注文住宅の区画だけが動き、仕入れ案件のステータスを区画から集約する
 * - 契約済みの注文住宅の土地は読み取り専用（区画のラベルと hidden。Bug #142）
 *
 * ⚠ 送る値は描いた画面から取る（Bug #47）。区画の一覧は、仕入れ案件を選んだときに API（procurement-info）から取り、
 *   入力エラーで戻った画面・編集画面はサーバが最初から渡す（決めたこと 8）。
 * ⚠ 土台の土地: 原価の行 30,000,000円・区画 1（132.69㎡・20,000,000円）・区画 2（100.50㎡・10,000,000円）
 *   → 按分は 20,000,000 / 10,000,000
 */
class ProcurementLotCustomOrderTest extends HousingScreenTestCase
{
    private const NOTE_UNAVAILABLE = '紐付け先から計算できません（分譲地・土地の全区画に販売価格が入っていない／仕入れ案件に原価が無い）。このまま保存すると空になります。';

    private const LEGACY_NOTICE = '区画を選ばずに保存すると、今のまま（区画なし）で保存します。';

    /** 区画 1 を取っている不動産の契約の断り（契約「勝山の土地」） */
    private const TAKEN_BY_CONTRACT = 'この区画（1号地）は契約「勝山の土地」に紐づいています。ほかの区画を選んでください。';

    /** @return array{0: ReProcurement, 1: ReProcurementLot, 2: ReProcurementLot} */
    private function twoLotLand(?int $secondPrice = 10000000, array $overrides = []): array
    {
        $land = $this->landProcurement($overrides);
        $first = $this->procurementLot($land, ['lot_number' => 1, 'area_sqm' => 132.69, 'selling_price' => 20000000]);
        $second = $this->procurementLot($land, ['lot_number' => 2, 'area_sqm' => 100.5, 'selling_price' => $secondPrice]);

        return [$land, $first, $second];
    }

    /** その土地の区画に建てる注文住宅（区画が null なら区画の無い注文住宅＝#21 の形） */
    private function orderOn(ReProcurement $land, ?ReProcurementLot $lot, array $overrides = []): HsCustomOrder
    {
        return $this->customOrder(array_merge([
            'land_source_type' => 'procurement',
            're_procurement_id' => $land->id,
            're_procurement_lot_id' => $lot?->id,
        ], $overrides));
    }

    /** 区画を取る不動産の契約（仕入れ土地販売。買主なし＝名前は「勝山の土地」） */
    private function takeByContract(ReProcurement $land, ReProcurementLot $lot): ReContract
    {
        return ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_land', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の土地', 'procurement_id' => $land->id, 'procurement_lot_id' => $lot->id,
            'contract_amount_land' => 10000000, 'cost_amount' => 10000000, 'created_by' => $this->user->id,
        ]);
    }

    /**
     * 区画を取る不動産の契約を、契約の登録の画面と同じ送り先から登録する（区画が成約になり、全区画が売れた土地は「販売済」になる）。
     * ⚠ takeByContract() は紐付けだけで区画の状態を動かさない（仕入れ案件は「販売中」のまま）
     */
    private function sellByContract(ReProcurement $land, ReProcurementLot $lot): void
    {
        $this->actingAs($this->user)->post(route('realestate.contracts.store'), [
            'contract_type' => 'procurement_land', 'procurement_id' => $land->id, 'procurement_lot_id' => $lot->id,
            'contract_date' => '2026-09-01', 'buyer_id' => $this->buyer('高橋', '一郎')->id,
            'contract_amount_land' => 20000000, 'cost_amount' => 15000000, 'property_name' => '勝山の土地',
        ])->assertSessionHasNoErrors();
        $this->assertSame('sold', $land->fresh()->status->value, 'テストの前提: 全区画が売れた土地が「販売済」になっていない');
    }

    private function createUrl(): string
    {
        return route('housing.custom-orders.create');
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
     * 登録画面で買主を選び、土地元を仕入れ案件にして $land を選んだあとのフォーム（区画の一覧は JS が API から取る）。
     * ⚠ API の応答はここで作る（あとで区画が取られても、この画面は開いたときの一覧のまま＝古い画面）。
     */
    private function createForm(string $html, ReProcurement $land, Buyer $buyer, string $lotSteps = ''): array
    {
        return $this->composedForm($html, route('housing.custom-orders.store'), 'customOrderForm', null, ['buyerSelect' => $this->chooseBuyer($buyer)],
            $this->selectProcurement($land) . $lotSteps, [$this->apiResponse(route('api.housing.procurement-info', $land))]);
    }

    /** 区画の選択欄の開始タグ */
    private function lotSelectTag(string $html): string
    {
        $this->assertSame(1, preg_match('/<select name="re_procurement_lot_id"[^>]*>/u', $html, $m), '区画の選択欄が無い');

        return $m[0];
    }

    /** @return array<int, string> 区画のステータス…・仕入れ案件のステータス */
    private function statuses(ReProcurement $land, ReProcurementLot ...$lots): array
    {
        return [...array_map(fn (ReProcurementLot $lot) => $lot->fresh()->status->value, $lots), $land->fresh()->status->value];
    }

    /**
     * 一覧の小窓のステップバーでステータスを変える（画面の JS が組んだ PATCH をそのまま送り、応答を JS に戻す）。
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: array} [サーバの応答, 応答を受けた JS の実行結果]
     */
    private function changeStatusFromList(HsCustomOrder $order, string $value, string $label): array
    {
        $html = $this->htmlOf(route('housing.custom-orders.index'));
        $steps = 'changeStatus(' . $order->id . ', ' . json_encode($order->order_code) . ', ' . json_encode($value) . ', ' . json_encode($label) . ');';

        $run = $this->driveAlpine($html, 'changeStatus', '({})', $steps);
        $this->assertSame(['PATCH', route('housing.custom-orders.update-status', $order)], [$run['requests'][0]['method'], $run['requests'][0]['url']]);
        $response = $this->actingAs($this->user)->sendCaptured($run['requests'][0]);

        return [$response, $this->driveAlpine($html, 'changeStatus', '({})', $steps, [$this->asFetchResponse($response)])];
    }

    // ============================================================
    // 登録画面の区画の選択欄
    // ============================================================

    public function test_the_create_screen_shows_the_lot_select_for_a_land_with_lots(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $buyer = $this->buyer('佐藤', '花子');
        $html = $this->htmlOf($this->createUrl());

        $form = $this->createForm($html, $land, $buyer);
        $this->assertSame(route('api.housing.procurement-info', $land), $form['run']['requests'][0]['url']);
        $this->assertSame([$first->optionLabel(), $second->optionLabel()], array_column($form['run']['state']['procurementLots'], 'label'), '区画の選択肢が出ていない');
        $this->assertSame('', $form['fields']['re_procurement_lot_id'], '選べる区画が 2 つなのに区画が選ばれている');

        // 区画の選択欄: 分譲地区画の選択欄と同じ作り（:selected・Top trap #3）・HTML の required は付けない（決めたこと 9）・
        // 区画の無い土地・土地元が仕入れ案件でないときは送らない
        $tag = $this->lotSelectTag($html);
        $this->assertStringNotContainsString('required', $tag, '区画の選択欄に required が付いている');
        $this->assertStringContainsString(':disabled="landSourceType !== \'procurement\' || procurementLots.length === 0"', $tag, '区画の選択欄が隠れたまま送られる');
        $this->assertSame(1, preg_match('/<option :value="lot\.id" :selected="String\(lot\.id\) === String\(selectedProcurementLotId\)" x-text="lot\.label"><\/option>/u', $html), '区画の選択肢に :selected が無い（Top trap #3）');

        // 選べる区画が 1 つなら、仕入れ案件を選んだときにそれを選ぶ（契約以降の注文住宅が区画 1 を取っている。見積りの注文住宅は取らない）
        $this->orderOn($land, $first, ['order_code' => 'CO-001', 'status' => 'contracted']);
        $this->orderOn($land, $second, ['order_code' => 'CO-002', 'status' => 'estimation']);
        $form = $this->createForm($this->htmlOf($this->createUrl()), $land, $buyer);
        $this->assertSame([$second->id], array_column($form['run']['state']['procurementLots'], 'id'), '取られた区画が選択肢に出ている（見積りの注文住宅の区画は出る）');
        $this->assertSame([(string) $second->id, '100.5'], [$form['fields']['re_procurement_lot_id'], $form['fields']['land_area_sqm']], '選べる区画が 1 つなのに選ばれていない');

        // 区画の無い土地: 区画の欄は送らない（今どおり仕入れ案件を選ぶだけ）
        $lotless = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '区画なしの土地']);
        $form = $this->createForm($this->htmlOf($this->createUrl()), $lotless, $buyer);
        $this->assertSame([], $form['run']['state']['procurementLots']);
        $this->assertArrayNotHasKey('re_procurement_lot_id', $form['fields'], '区画の無い土地で区画の欄が送られている');
    }

    public function test_choosing_a_lot_fills_the_land_area_and_the_land_cost(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $buyer = $this->buyer('佐藤', '花子');
        $url = $this->createUrl();

        $form = $this->createForm($this->htmlOf($url), $land, $buyer, $this->chooseLot($second));
        $this->assertSame([(string) $second->id, '100.5', 10000000],
            [$form['fields']['re_procurement_lot_id'], $form['fields']['land_area_sqm'], $form['run']['state']['computedLandCost']],
            '区画を選んでも土地面積・土地原価の自動の値が区画のものにならない');

        $html = $this->landed($this->submit($this->fill($form, ['order_name' => '佐藤邸 新築工事']), $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を登録しました。');
        $order = HsCustomOrder::sole();
        $this->assertSame([$land->id, $second->id, 10000000, '100.50'],
            [$order->re_procurement_id, $order->re_procurement_lot_id, $order->land_cost, $order->land_area_sqm]);
    }

    public function test_the_chosen_lot_survives_an_input_error(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $buyer = $this->buyer('佐藤', '花子');
        $url = $this->createUrl();
        $form = $this->createForm($this->htmlOf($url), $land, $buyer, $this->chooseLot($second));

        // 案件名を書き忘れて送る
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '案件名']));
        // 戻った画面をそのまま送り直す（区画は選び直さない。区画の一覧はサーバが描いて渡す）
        $again = $this->composedForm($html, route('housing.custom-orders.store'), 'customOrderForm', null, ['buyerSelect' => '']);
        $this->assertSame([], $again['run']['requests'], '戻った画面が区画の一覧を API から取り直している');
        $this->assertSame([(string) $land->id, (string) $second->id], [$again['fields']['re_procurement_id'], $again['fields']['re_procurement_lot_id'] ?? null], '入力エラーで戻ると選んでいた区画が消える');

        $html = $this->landed($this->submit($this->fill($again, ['order_name' => '佐藤邸 新築工事']), $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を登録しました。');
        $this->assertSame([$second->id, 10000000], [HsCustomOrder::sole()->re_procurement_lot_id, HsCustomOrder::sole()->land_cost]);
    }

    /** Review Focus 1: 手で組んだ送信で別の土地の区画・無い区画・配列を送る → 入力エラーで断り、何も保存しない */
    public function test_an_order_with_a_lot_of_another_land_is_refused(): void
    {
        [$land, $first] = $this->twoLotLand();
        $other = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $otherLot = $this->procurementLot($other);
        $url = $this->createUrl();
        $form = $this->fill($this->createForm($this->htmlOf($url), $land, $this->buyer('佐藤', '花子'), $this->chooseLot($first)), ['order_name' => '佐藤邸 新築工事']);

        foreach ([
            '別の土地の区画' => [(string) $otherLot->id, trans('validation.exists', ['attribute' => '区画'])],
            '存在しない区画' => ['999999', trans('validation.exists', ['attribute' => '区画'])],
            '配列' => [[(string) $first->id], trans('validation.integer', ['attribute' => '区画'])],
        ] as $case => [$value, $message]) {
            $html = $this->landed($this->submit($this->fill($form, ['re_procurement_lot_id' => $value]), $url));

            $this->assertErrorItem($html, $message);
            $this->assertSame(0, HsCustomOrder::count(), "{$case}で注文住宅ができた");
            $this->flushSession();
        }
    }

    public function test_a_lot_is_required_on_a_land_with_lots(): void
    {
        [$land] = $this->twoLotLand();
        $url = $this->createUrl();
        $form = $this->fill($this->createForm($this->htmlOf($url), $land, $this->buyer('佐藤', '花子')), ['order_name' => '佐藤邸 新築工事']);
        $this->assertSame('', $form['fields']['re_procurement_lot_id']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '区画を選んでください。');
        $this->assertSame(0, HsCustomOrder::count());

        // 選択欄ごと送られない手で組んだ送信も断る
        unset($form['fields']['re_procurement_lot_id']);
        $this->flushSession();
        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, '区画を選んでください。');
        $this->assertSame(0, HsCustomOrder::count());
    }

    /**
     * Ruling 8: 全区画がこの注文住宅には選べない土地は、仕入れ案件の選択肢に出さない（選んでも区画を選べず、保存で断られるだけなので）。
     * 見積りの注文住宅だけが付いた区画は取られていない。編集は今の仕入れ案件を残す。
     */
    public function test_a_land_whose_lots_are_all_taken_is_not_offered(): void
    {
        $full = $this->landProcurement(['procurement_code' => 'RE-PRC-001', 'property_name' => '全部取られた土地']);
        $this->takeByContract($full, $this->procurementLot($full));
        [$open] = $this->twoLotLand(10000000, ['procurement_code' => 'RE-PRC-002', 'property_name' => '空いている土地']);
        $estimateOnly = $this->landProcurement(['procurement_code' => 'RE-PRC-003', 'property_name' => '見積りだけの土地']);
        $this->orderOn($estimateOnly, $this->procurementLot($estimateOnly), ['order_code' => 'CO-001', 'status' => 'estimation']);
        $lotless = $this->landProcurement(['procurement_code' => 'RE-PRC-004', 'property_name' => '区画なしの土地']);
        $ids = ['data.procurements.map(function (p) { return p.id; })'];

        $run = $this->driveAlpine($this->htmlOf($this->createUrl()), 'customOrderForm', 'customOrderForm()', '', evaluate: $ids);
        $this->assertSame([$lotless->id, $estimateOnly->id, $open->id], $run['evaluated'][0], '全区画が取られた土地が選択肢に出ている（見積りの注文住宅だけの区画・区画の無い土地は出る）');

        // 編集: 今の仕入れ案件（全区画が取られた土地。区画の無い注文住宅＝#21 の形）は残す
        $legacy = $this->orderOn($full, null, ['order_code' => 'CO-002']);
        $run = $this->driveAlpine($this->htmlOf(route('housing.custom-orders.edit', $legacy)), 'customOrderForm', 'customOrderForm()', '', evaluate: $ids);
        $this->assertSame([$lotless->id, $estimateOnly->id, $open->id, $full->id], $run['evaluated'][0], '編集で今の仕入れ案件が選択肢から消えた');

        // 契約一覧から開く契約の編集も同じ（並びは仕入れ案件番号の順。今の仕入れ案件は残す）
        $estimate = $this->orderForContractEdit($open, null, ['order_code' => 'CO-003', 'status' => 'estimation']);
        $this->assertSame([$open->id, $estimateOnly->id, $lotless->id],
            $this->procurementOptionIds($this->htmlOf(route('housing.contracts.edit-custom-order', $estimate))), '契約の編集に全区画が取られた土地が出ている');
        $this->assertSame([$full->id, $open->id, $estimateOnly->id, $lotless->id],
            $this->procurementOptionIds($this->htmlOf(route('housing.contracts.edit-custom-order', $legacy))), '契約の編集で今の仕入れ案件が選択肢から消えた');
    }

    /** @return array<int, int> 契約の編集の仕入れ案件の選択肢の id（空の選択肢を除く） */
    private function procurementOptionIds(string $html): array
    {
        $this->assertSame(1, preg_match('/<select name="re_procurement_id" class="hc-select"[^>]*>(.*?)<\/select>/su', $html, $m), '契約の編集に仕入れ案件の選択欄が無い');
        preg_match_all('/<option value="(\d+)"/u', $m[1], $values);

        return array_map('intval', $values[1]);
    }

    // ============================================================
    // 1 区画 1 件（見積り以前の注文住宅は取らない・「契約」以降は取る）
    // ============================================================

    public function test_estimate_orders_can_share_an_untaken_lot(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $this->orderOn($land, $first, ['order_code' => 'CO-001', 'status' => 'estimation']);
        $url = $this->createUrl();
        // 買主は画面を開く前に作る（買主の選択肢は画面を開いたときのもの）
        $buyer = $this->buyer('佐藤', '花子');

        $form = $this->createForm($this->htmlOf($url), $land, $buyer, $this->chooseLot($first));
        $this->assertSame([$first->id, $second->id], array_column($form['run']['state']['procurementLots'], 'id'), '見積りの注文住宅の区画が取られたものとして外れた');
        $html = $this->landed($this->submit($this->fill($form, ['order_name' => '佐藤邸 新築工事', 'status' => 'estimation']), $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-002」を登録しました。');
        $this->assertSame([$first->id, $first->id], HsCustomOrder::orderBy('id')->pluck('re_procurement_lot_id')->all(), '見積りの注文住宅 2 件が同じ区画を持てない');
        $this->assertSame(['on_sale', 'on_sale', 'selling'], $this->statuses($land, $first, $second), '見積りの注文住宅で区画が動いた');
    }

    public function test_a_contracted_order_takes_the_lot(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $url = $this->createUrl();
        // 買主は画面を開く前に作る（買主の選択肢は画面を開いたときのもの）
        $buyer = $this->buyer('佐藤', '花子');

        $form = $this->createForm($this->htmlOf($url), $land, $buyer, $this->chooseLot($first));
        $html = $this->landed($this->submit($this->fill($form, ['order_name' => '佐藤邸 新築工事', 'status' => 'contracted']), $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を登録しました。');
        $this->assertSame(['sold', 'on_sale', 'selling'], $this->statuses($land, $first, $second), '契約で登録した注文住宅の区画だけが成約にならない');

        // ほかの建売・注文住宅（API）・契約（区画の選択肢）に区画 1 は選べない
        $this->assertSame([$second->id], array_column($this->actingAs($this->user)->getJson(route('api.housing.procurement-info', $land))->json('lots'), 'id'));
        $this->assertSame([$second->id], ProcurementLotClaims::selectableLots($land, null)->pluck('id')->all());
        $this->assertSame('この区画（1号地）は注文住宅「CO-001 佐藤邸 新築工事」に紐づいています。ほかの区画を選んでください。',
            ProcurementLotClaims::refusal($first->id, null, true));
    }

    /** 決めたこと 4: 見積りの注文住宅も、ほかに取られた区画を新しく選ぶ・変えることはできない */
    public function test_an_estimate_order_cannot_newly_choose_a_taken_lot(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $url = $this->createUrl();
        // 開いたあとでほかの人が区画 1 に契約を登録した画面（古い画面）から、見積りで登録する
        $stale = $this->fill($this->createForm($this->htmlOf($url), $land, $this->buyer('佐藤', '花子'), $this->chooseLot($first)),
            ['order_name' => '佐藤邸 新築工事', 'status' => 'estimation']);
        $this->takeByContract($land, $first);

        $html = $this->landed($this->submit($stale, $url));

        $this->assertErrorItem($html, self::TAKEN_BY_CONTRACT);
        $this->assertSame(0, HsCustomOrder::count());

        // Ruling 11: 戻った画面は、一覧に無くなった区画を選んだままにしない（区画 1 の按分も出さない）。フォームはそのまま組める
        $again = $this->composedForm($html, route('housing.custom-orders.store'), 'customOrderForm', null, ['buyerSelect' => '']);
        $this->assertSame([], $again['run']['requests'], '戻った画面が区画の一覧を API から取り直している');
        $this->assertSame([$second->id], array_column($again['run']['state']['procurementLots'], 'id'));
        $this->assertSame([(string) $land->id, ''], [$again['fields']['re_procurement_id'], $again['fields']['re_procurement_lot_id'] ?? null], '戻った画面が取られた区画を選んだまま');
        $this->assertNull($again['run']['state']['computedLandCost'], '戻った画面が取られた区画の按分を出している');

        // 画面の JS も同じ（サーバが一覧に無い区画を渡しても、init() で外す）
        $tampered = str_replace("selectedProcurementLotId: ''", "selectedProcurementLotId: '" . $first->id . "'", $html);
        $this->assertNotSame($html, $tampered);
        $again = $this->composedForm($tampered, route('housing.custom-orders.store'), 'customOrderForm', null, ['buyerSelect' => '']);
        $this->assertSame('', $again['fields']['re_procurement_lot_id'] ?? null, '画面の JS が一覧に無い区画を選んだまま');

        // 編集: 区画 2 を持つ見積りの注文住宅が区画 1 に変える（手で組んだ送信・古い画面）
        $order = $this->orderOn($land, $second, ['order_code' => 'CO-001', 'status' => 'estimation']);
        $edit = route('housing.custom-orders.edit', $order);
        $form = $this->customOrderEditForm($order);
        $form['fields']['re_procurement_lot_id'] = (string) $first->id;
        $this->flushSession();

        $html = $this->landed($this->submit($form, $edit));

        $this->assertErrorItem($html, self::TAKEN_BY_CONTRACT);
        $this->assertSame($second->id, $order->fresh()->re_procurement_lot_id);
    }

    /** 決めたこと 4: 今持っている区画は、あとでほかに取られていても、そのまま保存できる（「契約」へ進むのは断る） */
    public function test_an_estimate_order_keeps_its_lot_after_it_is_taken(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $order = $this->orderOn($land, $first, ['status' => 'estimation', 'land_cost' => 5000000, 'is_land_cost_manual' => false]);
        $this->takeByContract($land, $first);
        $url = route('housing.custom-orders.edit', $order);

        // 区画の一覧（API・編集画面）は今の区画を残す
        $api = route('api.housing.procurement-info', $land) . '?custom_order_id=' . $order->id;
        $this->assertSame([$first->id, $second->id], array_column($this->actingAs($this->user)->getJson($api)->json('lots'), 'id'), 'API が今の区画を外した');
        // 編集画面で仕入れ案件を選び直すと、この注文住宅を指定して区画の一覧を取る（今の区画が残る）
        $run = $this->driveAlpine($this->htmlOf($url), 'customOrderForm', 'customOrderForm()', 'data.selectedProcurementId = "' . $land->id . '"; data.onProcurementChange();',
            [$this->apiResponse($api)], true, ['data.procurementLots.map(function (l) { return l.id; })']);
        $this->assertSame($api, $run['requests'][0]['url'] ?? null, '編集画面が今の注文住宅を指定して区画の一覧を取らない');
        $this->assertSame([[$first->id, $second->id]], $run['evaluated'], '編集画面の区画の一覧から今の区画が消えた');
        $form = $this->fill($this->customOrderEditForm($order), ['notes' => '外構は別途']);
        $this->assertSame([(string) $land->id, (string) $first->id], [$form['fields']['re_procurement_id'], $form['fields']['re_procurement_lot_id'] ?? null], '編集画面で今の区画が選ばれていない');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $order->refresh();
        $this->assertSame([$first->id, '外構は別途', 5000000], [$order->re_procurement_lot_id, $order->notes, $order->land_cost], '何も変えない保存で区画か土地原価が動いた');

        // 「契約」へ進める保存は断る（区画を取るのはこの保存から）
        $html = $this->landed($this->submit($this->fill($this->customOrderEditForm($order), ['status' => 'contracted']), $url));

        $this->assertErrorItem($html, self::TAKEN_BY_CONTRACT);
        $this->assertSame(['estimation', $first->id], [$order->fresh()->status->value, $order->fresh()->re_procurement_lot_id]);
    }

    /**
     * 決めたこと 4・Review Focus 4: 土地の全区画が売れて仕入れ案件が「販売済」になっても、その土地の見積りの注文住宅（区画あり）と
     * 区画の無い注文住宅は、編集画面と契約の編集をそのまま保存できる（今の仕入れ案件は選択肢に残す）。新しい注文住宅には出さない
     */
    public function test_orders_on_a_sold_out_land_save_unchanged(): void
    {
        $land = $this->landProcurement();
        $lot = $this->procurementLot($land);
        $estimate = $this->orderForContractEdit($land, $lot, ['status' => 'estimation']);
        $legacy = $this->orderForContractEdit($land, null, ['order_code' => 'CO-002', 'status' => 'estimation']);
        $this->sellByContract($land, $lot);
        // 区画の無い「販売済」の土地（全区画が取られた土地の絞り込みでは外れない＝ステータスの絞り込みだけが外す）
        $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '区画なしの売れた土地', 'status' => 'sold']);

        $run = $this->driveAlpine($this->htmlOf($this->createUrl()), 'customOrderForm', 'customOrderForm()', '', evaluate: ['data.procurements.map(function (p) { return p.id; })']);
        $this->assertSame([], $run['evaluated'][0], '販売済の土地を新しい注文住宅の選択肢に出している');

        foreach ([[$estimate, $lot->id, 'CO-001'], [$legacy, null, 'CO-002']] as [$order, $lotId, $code]) {
            $url = route('housing.custom-orders.edit', $order);
            $form = $this->fill($this->customOrderEditForm($order), ['notes' => '外構は別途']);
            $this->assertSame([(string) $land->id, $lotId === null ? null : (string) $lotId],
                [$form['fields']['re_procurement_id'] ?? null, $form['fields']['re_procurement_lot_id'] ?? null], "{$code}: 編集画面で今の仕入れ案件か区画が選ばれていない");
            $this->assertFlash($this->landed($this->submit($form, $url)), 'success', "注文住宅案件「{$code}」を更新しました。");

            $contractUrl = route('housing.contracts.edit-custom-order', $order);
            $form = $this->fill($this->contractEditForm($order->fresh(), $this->htmlOf($contractUrl)), ['notes' => '鍵は 2 本']);
            $this->assertFlash($this->landed($this->submit($form, $contractUrl)), 'success', '契約を更新しました。');

            $order->refresh();
            $this->assertSame([$land->id, $lotId, 5000000, '鍵は 2 本'],
                [$order->re_procurement_id, $order->re_procurement_lot_id, $order->land_cost, $order->notes], "{$code}: そのまま保存で紐付けか土地原価が動いた");
        }
        $this->assertSame(['sold', 'sold'], $this->statuses($land, $lot), '見積りの注文住宅の保存で区画か土地の状態が動いた');
    }

    /** Review Focus 2: 区画をほかに取られた見積りの注文住宅を、一覧の小窓から「契約」へ進める → 422 で理由を出し、何も変えない */
    public function test_the_popup_refuses_contracted_on_a_taken_lot(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $order = $this->orderOn($land, $first, ['status' => 'estimation']);
        $this->takeByContract($land, $first);

        [$response, $after] = $this->changeStatusFromList($order, 'contracted', '契約');

        $response->assertStatus(422);
        $this->assertSame(['success' => false, 'message' => self::TAKEN_BY_CONTRACT], $response->json(), '断りの応答の形が違う（Laravel の errors を付けると小窓が文を 2 回出す）');
        $this->assertSame([self::TAKEN_BY_CONTRACT], $after['alerts'], '小窓が理由の文を 1 つだけ出していない');
        $this->assertSame([], $after['navigations'], '断られたのに画面を読み込み直した');
        $order->refresh();
        $this->assertSame(['estimation', $first->id], [$order->status->value, $order->re_procurement_lot_id]);
        $this->assertSame(['on_sale', 'on_sale', 'selling'], $this->statuses($land, $first, $second));
    }

    /**
     * 「契約」以降の注文住宅は区画をもう持っている。区画がほかの契約にも紐づいた食い違いのデータでも、そのまま保存する・
     * 「契約」以降どうしで進める（契約 → 着工）のは断らない（区画を取るのは「契約」をまたぐときだけ。Bug #130）。
     */
    public function test_a_contracted_order_is_not_refused_for_its_own_lot(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $order = $this->orderForContractEdit($land, $first, ['status' => 'contracted']);
        $this->takeByContract($land, $first);

        // 注文住宅の編集画面をそのまま保存できる
        $url = route('housing.custom-orders.edit', $order);
        $html = $this->landed($this->submit($this->fill($this->customOrderEditForm($order), ['notes' => '外構は別途']), $url));
        $this->assertStringNotContainsString(self::TAKEN_BY_CONTRACT, $html, '区画をもう持っている契約済みの注文住宅の保存を断った');
        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');

        // 契約の編集画面をそのまま保存できる
        $contractUrl = route('housing.contracts.edit-custom-order', $order);
        $html = $this->landed($this->submit($this->fill($this->contractEditForm($order->fresh(), $this->htmlOf($contractUrl)), ['notes' => '鍵は 2 本']), $contractUrl));
        $this->assertStringNotContainsString(self::TAKEN_BY_CONTRACT, $html, '区画をもう持っている契約済みの注文住宅の契約の保存を断った');
        $this->assertFlash($html, 'success', '契約を更新しました。');

        // 一覧の小窓で「契約」以降どうし（契約 → 着工）に進められる
        [$response] = $this->changeStatusFromList($order->fresh(), 'construction', '着工');
        $response->assertOk();
        $order->refresh();
        $this->assertSame(['construction', $first->id, '鍵は 2 本'], [$order->status->value, $order->re_procurement_lot_id, $order->notes]);
    }

    public function test_crossing_contracted_moves_only_its_lot(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $order = $this->orderOn($land, $first, ['status' => 'estimation']);

        [$response, $after] = $this->changeStatusFromList($order, 'contracted', '契約');
        $response->assertOk();
        $this->assertSame(['reload'], $after['navigations']);
        $this->assertSame(['sold', 'on_sale', 'selling'], $this->statuses($land, $first, $second), '契約へ進めても、その区画だけが成約にならない');

        [$response] = $this->changeStatusFromList($order->fresh(), 'design', '設計');
        $response->assertOk();
        $this->assertSame(['on_sale', 'on_sale', 'selling'], $this->statuses($land, $first, $second), '設計へ戻しても区画が販売中に戻らない');

        // 契約以降で削除すると販売中に戻す（詳細の削除のフォームから）
        $this->changeStatusFromList($order->fresh(), 'contracted', '契約')[0]->assertOk();
        $show = route('housing.custom-orders.show', $order);
        $html = $this->landed($this->submit($this->parseForm($this->htmlOf($show), 'action="' . route('housing.custom-orders.destroy', $order) . '"'), $show));
        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を削除しました。');
        $this->assertSame(['on_sale', 'on_sale', 'selling'], $this->statuses($land, $first, $second), '契約以降で削除しても区画が販売中に戻らない');
    }

    // ============================================================
    // 編集画面（契約済みは読み取り専用）
    // ============================================================

    public function test_a_contracted_order_shows_its_lot_read_only(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $order = $this->orderOn($land, $second, ['status' => 'contracted', 'land_cost' => 5000000, 'is_land_cost_manual' => false]);
        $url = route('housing.custom-orders.edit', $order);

        $html = $this->htmlOf($url);
        $this->assertSame(0, preg_match('/<select name="re_procurement_lot_id"/u', $html), '契約済みなのに区画を選べる');
        $this->assertSame(1, preg_match('/<input type="hidden" name="re_procurement_lot_id" value="' . $second->id . '">/u', $html), '契約済みの区画の hidden が無い');
        $this->assertStringContainsString('区画 2号地 100.50㎡（30.40坪） 販売価格 10,000,000円', $html);
        // 画面の JS も紐付けは変わっていないとみなす（区画を紐付けに含める。savedLink の procurementLot）
        $run = $this->driveAlpine($html, 'customOrderForm', 'customOrderForm()', '', evaluate: ['landCostView()']);
        $this->assertSame([false, 5000000, true], [$run['evaluated'][0]['willCompute'], $run['evaluated'][0]['value'], $run['evaluated'][0]['refreshOffered']]);

        $html = $this->landed($this->submit($this->fill($this->customOrderEditForm($order), ['notes' => '外構は別途']), $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $this->assertSame([$second->id, 5000000], [$order->fresh()->re_procurement_lot_id, $order->fresh()->land_cost]);

        // 区画だけを変える手で組んだ送信は断る（Bug #142）
        $form = $this->customOrderEditForm($order);
        $form['fields']['re_procurement_lot_id'] = (string) $first->id;
        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, HsCustomOrder::LAND_LOCKED_BY_CONTRACT);
        $this->assertSame($second->id, $order->fresh()->re_procurement_lot_id);
    }

    /** Review Focus 4: 区画の無い注文住宅（#21 の形）の土地に区画を足しても、編集画面・小窓・契約の編集をそのまま通せる */
    public function test_a_legacy_order_without_a_lot_saves_as_is(): void
    {
        $land = $this->landProcurement();
        $order = $this->orderOn($land, null, [
            'status' => 'estimation', 'land_cost' => 5000000, 'is_land_cost_manual' => false,
            'contract_date' => '2026-09-10', 'building_contract_price' => 25000000, 'building_cost' => 20000000,
        ]);
        // あとから区画を足す（2 区画。区画の無い注文住宅の按分は空になるが、紐付けを変えない保存では計算し直さない）
        $first = $this->procurementLot($land, ['lot_number' => 1]);
        $second = $this->procurementLot($land, ['lot_number' => 2, 'selling_price' => 10000000]);
        $url = route('housing.custom-orders.edit', $order);

        $html = $this->htmlOf($url);
        $this->assertStringContainsString(self::LEGACY_NOTICE, $html);
        $run = $this->driveAlpine($html, 'customOrderForm', 'customOrderForm()', '', evaluate: ['data.showsLegacyNotice()', 'data.procurementLotMissing()']);
        $this->assertSame([true, false], $run['evaluated'], '区画の無い注文住宅の案内が出ていない（区画を選ばずに保存できる）');
        $form = $this->fill($this->customOrderEditForm($order), ['notes' => '外構は別途']);
        $this->assertSame('', $form['fields']['re_procurement_lot_id'] ?? null, '区画が勝手に選ばれた');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $order->refresh();
        $this->assertSame([null, 5000000, '外構は別途'], [$order->re_procurement_lot_id, $order->land_cost, $order->notes]);

        // 小窓で「契約」へ進めても、区画は動かない
        $this->changeStatusFromList($order, 'contracted', '契約')[0]->assertOk();
        $this->assertSame(['on_sale', 'on_sale', 'selling'], $this->statuses($land, $first, $second));

        // 契約済み（土地は読み取り専用）の編集画面・契約の編集もそのまま保存できる
        $order->refresh();
        $html = $this->landed($this->submit($this->fill($this->customOrderEditForm($order), ['notes' => '鍵は 2 本']), $url));
        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $contractUrl = route('housing.contracts.edit-custom-order', $order);
        $html = $this->landed($this->submit($this->fill($this->contractEditForm($order, $this->htmlOf($contractUrl)), ['notes' => '外構は別途']), $contractUrl));
        $this->assertFlash($html, 'success', '契約を更新しました。');
        $order->refresh();
        $this->assertSame([null, 5000000, 'contracted'], [$order->re_procurement_lot_id, $order->land_cost, $order->status->value]);
    }

    /** Review Focus 5 の注文住宅: 2 区画以上の土地で区画の 1 つに販売価格が無い → 土地原価の自動の値は空（区画を選ぶ前は「計算できません」を出さない） */
    public function test_auto_land_cost_is_empty_when_a_lot_has_no_price(): void
    {
        [$land, $first] = $this->twoLotLand(null);
        $buyer = $this->buyer('佐藤', '花子');
        $url = $this->createUrl();
        $html = $this->htmlOf($url);
        $this->assertStringContainsString(self::NOTE_UNAVAILABLE, $html);
        $responses = [$this->apiResponse(route('api.housing.procurement-info', $land))];
        $evaluate = ['landCostView()'];

        $run = $this->driveAlpine($html, 'customOrderForm', 'customOrderForm()', $this->selectProcurement($land), $responses, true, $evaluate);
        $this->assertSame([false, null], [$run['evaluated'][0]['unavailable'], $run['evaluated'][0]['value']], '区画を選ぶ前に「計算できません」が出た（Ruling 9）');

        $run = $this->driveAlpine($html, 'customOrderForm', 'customOrderForm()', $this->selectProcurement($land) . $this->chooseLot($first), $responses, true, $evaluate);
        $this->assertSame([true, null], [$run['evaluated'][0]['unavailable'], $run['evaluated'][0]['value']], '按分できない区画なのに「計算できません」が出ない');

        $this->landed($this->submit($this->fill($this->createForm($html, $land, $buyer, $this->chooseLot($first)), ['order_name' => '佐藤邸 新築工事']), $url));

        $this->assertSame([$first->id, null], [HsCustomOrder::sole()->re_procurement_lot_id, HsCustomOrder::sole()->land_cost], '按分できない区画で土地原価が入った');
    }

    // ============================================================
    // 契約一覧から開く契約の編集
    // ============================================================

    /** 契約の編集の画面が保存に要る項目（契約日・買主・金額）を持つ注文住宅 */
    private function orderForContractEdit(ReProcurement $land, ?ReProcurementLot $lot, array $overrides = []): HsCustomOrder
    {
        return $this->orderOn($land, $lot, array_merge([
            'contract_date' => '2026-09-10', 'building_contract_price' => 25000000, 'building_cost' => 20000000,
            'land_cost' => 5000000, 'is_land_cost_manual' => false,
        ], $overrides));
    }

    private function contractEditForm(HsCustomOrder $order, string $html, string $js = ''): array
    {
        return $this->composedForm($html, route('housing.contracts.update-custom-order', $order), 'customOrderEditForm', null,
            ['datePicker' => '', 'buyerSelect' => ''], $js);
    }

    public function test_the_contract_edit_screen_offers_lots_before_contract(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $order = $this->orderForContractEdit($land, $first, ['status' => 'estimation']);
        $url = route('housing.contracts.edit-custom-order', $order);

        // 契約前: 区画の選択欄（今の区画が選ばれている。:selected・required なし・区画の無い土地では送らない）
        $html = $this->htmlOf($url);
        $tag = $this->lotSelectTag($html);
        $this->assertStringNotContainsString('required', $tag, '区画の選択欄に required が付いている');
        $this->assertStringContainsString(':disabled="landSourceType !== \'procurement\' || procurementLots.length === 0"', $tag);
        $this->assertSame(1, preg_match('/<option :value="lot\.id" :selected="String\(lot\.id\) === String\(reProcurementLotId\)" x-text="lot\.label"><\/option>/u', $html), '区画の選択肢に :selected が無い（Top trap #3）');
        $form = $this->contractEditForm($order, $html);
        $this->assertSame([], $form['run']['requests'], '契約の編集画面が区画の一覧を API から取り直している');
        $this->assertSame([(string) $land->id, (string) $first->id], [$form['fields']['re_procurement_id'], $form['fields']['re_procurement_lot_id'] ?? null]);

        // 区画を 2 に変えて保存する（土地原価は区画 2 の按分）
        $html = $this->landed($this->submit($this->contractEditForm($order, $html, 'data.reProcurementLotId = "' . $second->id . '";'), $url));
        $this->assertFlash($html, 'success', '契約を更新しました。');
        $this->assertSame([$second->id, 10000000], [$order->fresh()->re_procurement_lot_id, $order->fresh()->land_cost]);

        // 仕入れ案件を変えると、その土地の区画を API から取る（今の注文住宅を指定する）。選べる区画が 1 つならそれを選ぶ
        $other = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $otherLot = $this->procurementLot($other);
        $api = route('api.housing.procurement-info', $other) . '?custom_order_id=' . $order->id;
        $html = $this->htmlOf($url);
        $run = $this->driveAlpine($html, 'customOrderEditForm', $this->xData($html, 'customOrderEditForm'),
            'data.reProcurementId = "' . $other->id . '"; data.onProcurementChange();', [$this->apiResponse($api)], true, ['procurementLots.map(function (l) { return l.id; })', 'reProcurementLotId']);
        $this->assertSame($api, $run['requests'][0]['url'] ?? null, '仕入れ案件を変えても、今の注文住宅を指定して区画の一覧を取らない');
        $this->assertSame([[$otherLot->id], (string) $otherLot->id], $run['evaluated'], '選べる区画が 1 つなのに選ばれていない');

        // 契約済み: 区画は読み取り専用＋hidden（Bug #142）。そのまま保存でき、区画だけを変える送信は断る
        $order->update(['status' => 'contracted']);
        $html = $this->htmlOf($url);
        $this->assertSame(0, preg_match('/<select name="re_procurement_lot_id"/u', $html), '契約済みなのに区画を選べる');
        $this->assertSame(1, preg_match('/<input type="hidden" name="re_procurement_lot_id" value="' . $second->id . '">/u', $html), '契約済みの区画の hidden が無い');
        $this->assertStringContainsString('区画 2号地 100.50㎡（30.40坪） 販売価格 10,000,000円', $html);
        $html = $this->landed($this->submit($this->fill($this->contractEditForm($order, $html), ['notes' => '鍵は 2 本']), $url));
        $this->assertFlash($html, 'success', '契約を更新しました。');
        $this->assertSame([$second->id, 10000000], [$order->fresh()->re_procurement_lot_id, $order->fresh()->land_cost], '何も変えない保存で区画か土地原価が動いた');

        $form = $this->contractEditForm($order, $this->htmlOf($url));
        $form['fields']['re_procurement_lot_id'] = (string) $first->id;
        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, HsCustomOrder::LAND_LOCKED_BY_CONTRACT);
        $this->assertSame($second->id, $order->fresh()->re_procurement_lot_id);
    }

    /** 土地元を仕入れ案件からほかの種別に変えると、土地の区画も外す（注文住宅の編集・契約の編集。手で組んだ送信で区画の欄が残っていても） */
    public function test_changing_the_land_type_drops_the_lot(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $projectLot = $this->lot($this->project(), 3);
        $order = $this->orderForContractEdit($land, $second, ['status' => 'estimation']);

        $url = route('housing.custom-orders.edit', $order);
        $form = $this->customOrderEditForm($order);
        $this->assertSame((string) $second->id, $form['fields']['re_procurement_lot_id'] ?? null, 'テストの前提: 区画の欄が送られていない');
        $form['fields']['land_source_type'] = 'project_lot';
        $form['fields']['re_project_lot_id'] = (string) $projectLot->id;
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '注文住宅案件「CO-001」を更新しました。');
        $order->refresh();
        $this->assertSame(['project_lot', $projectLot->id, null, null],
            [$order->land_source_type->value, $order->re_project_lot_id, $order->re_procurement_id, $order->re_procurement_lot_id], '分譲地区画に変えても土地の区画が残った');

        $order->update(['land_source_type' => 'procurement', 're_project_lot_id' => null, 're_procurement_id' => $land->id, 're_procurement_lot_id' => $second->id]);
        $contractUrl = route('housing.contracts.edit-custom-order', $order);
        $form = $this->contractEditForm($order->fresh(), $this->htmlOf($contractUrl));
        $this->assertSame((string) $second->id, $form['fields']['re_procurement_lot_id'] ?? null, 'テストの前提: 区画の欄が送られていない');
        $form['fields']['land_source_type'] = 'customer_land';
        $this->assertFlash($this->landed($this->submit($form, $contractUrl)), 'success', '契約を更新しました。');
        $order->refresh();
        $this->assertSame(['customer_land', null, null], [$order->land_source_type->value, $order->re_procurement_id, $order->re_procurement_lot_id], 'お客様の土地に変えても土地の区画が残った');
    }

    /** 契約の編集でも、取られた区画は断り、戻った画面はその区画を選んだままにしない（Ruling 11） */
    public function test_the_contract_edit_refuses_a_taken_lot(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $order = $this->orderForContractEdit($land, $second, ['status' => 'estimation']);
        $url = route('housing.contracts.edit-custom-order', $order);
        $stale = $this->contractEditForm($order, $this->htmlOf($url), 'data.reProcurementLotId = "' . $first->id . '";');
        $this->takeByContract($land, $first);

        $html = $this->landed($this->submit($stale, $url));

        $this->assertErrorItem($html, self::TAKEN_BY_CONTRACT);
        $this->assertSame($second->id, $order->fresh()->re_procurement_lot_id);
        $again = $this->contractEditForm($order, $html);
        $this->assertSame([$second->id], array_column($again['run']['state']['procurementLots'], 'id'));
        $this->assertSame('', $again['fields']['re_procurement_lot_id'] ?? null, '戻った画面が取られた区画を選んだまま');

        // 画面の JS も、一覧に無い区画を選ばない（サーバが外し忘れても。Ruling 11）
        $tampered = str_replace("reProcurementLotId: ''", "reProcurementLotId: '" . $first->id . "'", $html);
        $this->assertNotSame($html, $tampered, 'テストの前提: 区画の初めの値を書き換えられない');
        $this->assertSame('', $this->contractEditForm($order, $tampered)['fields']['re_procurement_lot_id'] ?? null, '画面の JS が一覧に無い区画を選んだまま');
    }

    // ============================================================
    // 詳細
    // ============================================================

    public function test_the_show_screen_shows_the_lot(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $order = $this->orderOn($land, $second);

        $html = $this->htmlOf(route('housing.custom-orders.show', $order));

        $this->assertStringContainsString('RE-PRC-001 勝山の土地 &gt; 2号地', $html);
        $this->assertStringContainsString('区画 2号地 100.50㎡（30.40坪）', $html);
        $this->assertStringNotContainsString('区画 1号地', $html, '注文住宅の区画でなく土地の別の区画を出している');
        // 契約一覧から開く契約の詳細の「建築土地」も区画まで出す（分譲地区画と同じ形）
        $this->assertSame(1, preg_match('/勝山の土地\s*2号地/u', $this->htmlOf(route('housing.contracts.show-custom-order', $order))), '契約の詳細に区画が出ていない');

        // 区画の無い注文住宅（区画のある土地でも）は区画を出さない
        $legacy = $this->orderOn($land, null, ['order_code' => 'CO-002']);
        $html = $this->htmlOf(route('housing.custom-orders.show', $legacy));
        $this->assertSame(0, preg_match('/区画 \d+号地/u', $html), '区画の無い注文住宅に区画を出している');
        $this->assertSame(0, preg_match('/勝山の土地\s*\d+号地/u', $this->htmlOf(route('housing.contracts.show-custom-order', $legacy))), '区画の無い注文住宅の契約の詳細に区画を出している');
    }
}
