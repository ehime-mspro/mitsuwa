<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\LotStatus;
use App\Http\Controllers\RealEstate\ReContractController;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use App\Models\ReProcurementLot;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 不動産の契約（仕入れ系）で土地の区画を選ぶ（設計書 §5.3〜§5.6）。
 *
 * - 区画のある土地を選ぶと区画の選択欄が出て、区画は必須（区画の無い保存済みの契約＝#21 の形は、何も変えずに保存できる）
 * - 1 つの区画に付けられる契約は 1 件だけ（「この区画（…号地）は…に紐づいています」で断る）
 * - 区画を選ぶと、土地の契約額に区画の販売価格・原価に按分した額（区画 0〜1 の土地は全額）が入る
 * - 区画のステータスは契約に付いて動き、仕入れ案件のステータスは区画から集約する（区画の無い土地は今どおり）
 * - 契約の詳細は契約の区画を出し、区画が 2 つ以上の土地では「現在の仕入れ原価」を按分した額にして比べる（Bug #46）
 *
 * ⚠ 送る値は描いた画面から取る（Bug #47）。区画の一覧はサーバが最初から渡す（API を足さない。決めたこと 8）。
 * ⚠ 土台の土地: 区画 1（20,000,000円）・区画 2（10,000,000円）・原価の行 30,000,000円 → 按分は 20,000,000 / 10,000,000
 */
class ContractProcurementLotTest extends RealEstateScreenTestCase
{
    private const NOTE = '※ ステータスが「販売中」で、契約の無い案件と、空いている区画のある土地を表示';

    private const LEGACY_NOTICE = '区画を選ばずに保存すると、今のまま（区画なし）で保存します。';

    private function landWithCost(array $overrides = [], int $cost = 30000000): ReProcurement
    {
        $land = $this->landProcurement($overrides);
        ReProcurementCost::create(['procurement_id' => $land->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => $cost]);

        return $land;
    }

    /** @return array{0: ReProcurement, 1: ReProcurementLot, 2: ReProcurementLot} */
    private function twoLotLand(array $overrides = [], ?int $secondPrice = 10000000): array
    {
        $land = $this->landWithCost($overrides);
        $first = $this->procurementLot($land, ['lot_number' => 1, 'selling_price' => 20000000]);
        $second = $this->procurementLot($land, ['lot_number' => 2, 'selling_price' => $secondPrice]);

        return [$land, $first, $second];
    }

    private function contractOn(ReProcurement $land, ?ReProcurementLot $lot, string $name = '勝山の土地'): ReContract
    {
        return ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_land', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => $name, 'procurement_id' => $land->id, 'procurement_lot_id' => $lot?->id, 'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 20000000, 'cost_amount' => 20000000, 'created_by' => $this->user->id,
        ]);
    }

    private function createUrl(): string
    {
        return route('realestate.contracts.create');
    }

    /** その区画に建つ建売（区画を取る。契約の前から） */
    private function buildingOn(ReProcurement $land, ReProcurementLot $lot, string $code = 'HS-LOT-001', string $name = '区画の建売'): HsProperty
    {
        return HsProperty::create([
            'property_code' => $code, 'property_name' => $name, 'status' => 'construction', 'address' => '松山市石井町1-2-3',
            'land_source_type' => 'procurement', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $lot->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function editNeedle(ReContract $contract): string
    {
        return 'action="' . route('realestate.contracts.update', $contract) . '"';
    }

    /** @return array{0: mixed, 1: mixed, 2: mixed} 送る区画・土地の契約額・原価（区画の欄が送られなければ null） */
    private function lotAndAmounts(array $form): array
    {
        return [$form['fields']['procurement_lot_id'] ?? null, $form['fields']['contract_amount_land'] ?? null, $form['fields']['cost_amount'] ?? null];
    }

    /**
     * 登録画面で土地の契約を選び、仕入れ案件を選んで原価を取ったあとのフォーム。$lotSteps は仕入れ案件を選んだあとの手順
     * （原価の API の応答は手順のあとで JS に戻る＝区画を選んだあとで原価が返ってきても、区画の原価を入れ直すかまで見る）。
     */
    private function createForm(string $html, ReProcurement $land, string $lotSteps = ''): array
    {
        return $this->browserForm($html, 'action="' . route('realestate.contracts.store') . '"', 'contractForm',
            'data.contractType = "procurement_land"; data.onTypeChange(); data.procurementId = "' . $land->id . '"; data.onProcurementChange();' . $lotSteps,
            [$this->apiResponse(route('api.realestate.procurement-cost', $land))]);
    }

    private function chooseLot(ReProcurementLot $lot): string
    {
        return ' data.procurementLotId = "' . $lot->id . '"; data.onProcurementLotChange();';
    }

    private function completed(array $form, string $buyerLast = '山田'): array
    {
        return $this->fill($form, ['contract_date' => '2026-10-01', 'buyer_id' => (string) $this->buyer($buyerLast, '太郎')->id]);
    }

    private function lotOptionIds(string $html, string $function, ReProcurement $land, string $factory): array
    {
        return $this->driveAlpine($html, $function, $factory,
            'data.procurementId = "' . $land->id . '";', evaluate: ['data.lotOptions().map(function (o) { return o.id; })'])['evaluated'][0];
    }

    // ============================================================
    // 登録画面の区画の選択欄
    // ============================================================

    public function test_the_create_screen_offers_the_lots_of_the_chosen_land(): void
    {
        [$land, $first, $second] = $this->twoLotLand();

        $html = $this->htmlOf($this->createUrl());

        $run = $this->driveAlpine($html, 'contractForm', 'contractForm()', 'data.contractType = "procurement_land"; data.procurementId = "' . $land->id . '";',
            evaluate: ['data.lotOptions().map(function (o) { return o.label; })', 'data.hasLotOptions()']);
        $this->assertSame([[$first->optionLabel(), $second->optionLabel()], true], $run['evaluated']);
        $this->assertSame('1号地 132.69㎡（40.13坪） 販売価格 20,000,000円 販売中', $first->optionLabel());
        $this->assertStringContainsString(self::NOTE, $html);
        $this->assertStringNotContainsString('procurementLotLabel', $html, '前の「区画: …」の注記が残っている');
        // 区画の選択欄: 選べる区画が無い土地では隠れていて送らない・HTML の required は付けない（必須はサーバが決める。決めたこと 9）
        $this->assertSame(1, preg_match('/<div[^>]*x-show="hasLotOptions\(\)"[^>]*style="display: none;"/u', $html), '区画の欄が最初から隠れていない');
        $this->assertSame(1, preg_match('/<select name="procurement_lot_id"[^>]*:disabled="!isProcurement\(\) \|\| !hasLotOptions\(\)"[^>]*>/u', $html), '区画の選択欄が隠れたまま送られる');
        $this->assertSame(0, preg_match('/<select name="procurement_lot_id"[^>]*\brequired\b/u', $html), '区画の選択欄に required が付いている');
        $this->assertSame(1, preg_match('/<option :value="lot\.id" :selected="String\(lot\.id\) === String\(procurementLotId\)" x-text="lot\.label"><\/option>/u', $html), '区画の選択肢に :selected が無い（Top trap #3）');
    }

    public function test_choosing_a_lot_fills_the_land_amount_and_the_allocated_cost(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $url = $this->createUrl();

        $form = $this->createForm($this->htmlOf($url), $land, $this->chooseLot($second));
        $this->assertSame([(string) $second->id, '10000000', '10000000'],
            [$form['fields']['procurement_lot_id'], $form['fields']['contract_amount_land'], $form['fields']['cost_amount']],
            '区画を選んでも販売価格・按分した原価が入らない（原価の API の応答のあとで全額に戻っていないかも）');

        $html = $this->landed($this->submit($this->completed($form), $url));

        $this->assertFlash($html, 'success', '契約を登録しました。');
        $contract = ReContract::sole();
        $this->assertSame([$second->id, 10000000, 10000000, 0], [$contract->procurement_lot_id, $contract->contract_amount_land, $contract->cost_amount, $contract->gross_profit]);
    }

    public function test_a_single_selectable_lot_is_preselected(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $this->contractOn($land, $first);

        $form = $this->createForm($this->htmlOf($this->createUrl()), $land);

        $this->assertSame([(string) $second->id, '10000000', '10000000'],
            [$form['fields']['procurement_lot_id'], $form['fields']['contract_amount_land'], $form['fields']['cost_amount']],
            '選べる区画が 1 つなのに選ばれていない');
    }

    public function test_two_contracts_on_one_land_need_different_lots(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $url = $this->createUrl();
        $page = $this->htmlOf($url);
        $stale = $this->completed($this->createForm($page, $land, $this->chooseLot($first)), '佐藤');

        $this->landed($this->submit($this->completed($this->createForm($page, $land, $this->chooseLot($first))), $url));
        $html = $this->landed($this->submit($this->completed($this->createForm($this->htmlOf($url), $land, $this->chooseLot($second)), '鈴木'), $url));
        $this->assertFlash($html, 'success', '契約を登録しました。');
        $this->assertSame([$first->id, $second->id], ReContract::orderBy('id')->pluck('procurement_lot_id')->all());

        // 開いたあとでほかの人が区画 1 の契約を登録した画面（古い画面）から送る
        $html = $this->landed($this->submit($stale, $url));

        $this->assertErrorItem($html, 'この区画（1号地）は契約「勝山の土地（山田 太郎 様）」に紐づいています。ほかの区画を選んでください。');
        $this->assertSame(2, ReContract::count());
    }

    public function test_taken_lots_and_full_lands_are_not_offered(): void
    {
        [$land, $first, $second] = $this->twoLotLand(['procurement_code' => 'RE-PRC-001']);
        $this->contractOn($land, $first);
        $full = $this->landWithCost(['procurement_code' => 'RE-PRC-002', 'property_name' => '全部取られた土地']);
        $this->contractOn($full, $this->procurementLot($full), '全部取られた土地');
        $lotless = $this->landWithCost(['procurement_code' => 'RE-PRC-003', 'property_name' => '契約のある区画なしの土地']);
        $this->contractOn($lotless, null, '契約のある区画なしの土地');
        $free = $this->landWithCost(['procurement_code' => 'RE-PRC-004', 'property_name' => '空いている区画なしの土地']);

        $html = $this->htmlOf($this->createUrl());

        $this->assertSame([$second->id], $this->lotOptionIds($html, 'contractForm', $land, 'contractForm()'), '取られた区画が選択肢に出ている');
        $this->assertStringContainsString('<option value="' . $land->id . '">RE-PRC-001 勝山の土地</option>', $html);
        $this->assertStringContainsString('<option value="' . $free->id . '">RE-PRC-004 空いている区画なしの土地</option>', $html);
        $this->assertStringNotContainsString('<option value="' . $full->id . '">', $html, '全区画が取られた土地が選択肢に出ている');
        $this->assertStringNotContainsString('<option value="' . $lotless->id . '">', $html, '契約のある区画なしの土地が選択肢に出ている（Bug #139）');
    }

    public function test_a_lot_is_required_on_a_land_with_lots(): void
    {
        [$land] = $this->twoLotLand();
        $url = $this->createUrl();
        $form = $this->createForm($this->htmlOf($url), $land);
        $this->assertSame('', $form['fields']['procurement_lot_id'], '区画を選ばずに送れていない（選べる区画が 2 つなら選んでおかない）');

        $html = $this->landed($this->submit($this->completed($form), $url));

        $this->assertErrorItem($html, '区画を選んでください。');
        $this->assertSame(0, ReContract::count());
    }

    public function test_a_lot_of_another_land_is_refused(): void
    {
        [$land, $first] = $this->twoLotLand();
        $other = $this->landWithCost(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $otherLot = $this->procurementLot($other);
        $url = $this->createUrl();
        $form = $this->completed($this->createForm($this->htmlOf($url), $land, $this->chooseLot($first)));

        foreach ([
            '別の土地の区画' => [(string) $otherLot->id, trans('validation.exists', ['attribute' => '区画'])],
            '存在しない区画' => ['999999', trans('validation.exists', ['attribute' => '区画'])],
            '配列' => [[(string) $first->id], trans('validation.integer', ['attribute' => '区画'])],
        ] as $case => [$value, $message]) {
            $html = $this->landed($this->submit($this->fill($form, ['procurement_lot_id' => $value]), $url));

            $this->assertErrorItem($html, $message);
            $this->assertSame(0, ReContract::count(), "{$case}で契約ができた");
        }
        $this->assertSame([LotStatus::OnSale, LotStatus::OnSale], [$first->fresh()->status, $otherLot->fresh()->status]);
    }

    // ============================================================
    // 区画の無い保存済みの契約（#21 の形）
    // ============================================================

    public function test_a_legacy_contract_without_a_lot_saves_as_is(): void
    {
        $land = $this->landWithCost(['status' => 'sold']);
        $contract = $this->contractOn($land, null);

        // あとから区画を 1 つ足した形（この変更の前のデータ: 区画は成約で作られ、契約とはつながっていない。
        // 今は追加のフォームから足すと契約につながる＝ProcurementLotLinkScreensTest）
        $lot = $this->procurementLot($land, ['status' => 'sold']);

        $url = route('realestate.contracts.edit', $contract);
        $needle = 'action="' . route('realestate.contracts.update', $contract) . '"';
        $edit = $this->htmlOf($url);
        $form = $this->browserForm($edit, $needle, 'contractEditForm');
        $this->assertSame(['', (string) $land->id], [$form['fields']['procurement_lot_id'] ?? null, $form['fields']['procurement_id'] ?? null], '区画が勝手に選ばれた（か区画の欄が送られない）');
        $run = $this->driveAlpine($edit, 'contractEditForm', 'contractEditForm()', '', evaluate: ['data.lotOptions().map(function (o) { return o.id; })', 'data.showsLegacyNotice()']);
        $this->assertSame([[$lot->id], true], $run['evaluated'], '区画の選択欄か案内の文が出ていない');
        $this->assertStringContainsString(self::LEGACY_NOTICE, $edit);

        // そのまま保存できる（区画は空のまま）
        $html = $this->landed($this->submit($form, $url));
        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $this->assertNull($contract->fresh()->procurement_lot_id);
        $this->assertSame(LotStatus::Sold, $lot->fresh()->status);

        // 次に区画 1 を選んで保存できる
        $form = $this->browserForm($this->htmlOf($url), $needle, 'contractEditForm', $this->chooseLot($lot));
        $html = $this->landed($this->submit($form, $url));
        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $this->assertSame($lot->id, $contract->fresh()->procurement_lot_id);
        $this->assertSame(['sold', 'sold'], [$lot->fresh()->status->value, $land->fresh()->status->value]);

        $run = $this->driveAlpine($this->htmlOf($url), 'contractEditForm', 'contractEditForm()', '', evaluate: ['data.showsLegacyNotice()']);
        $this->assertSame([false], $run['evaluated'], '区画を選んだあとも案内の文が出ている');
    }

    // ============================================================
    // 区画が自動で入れた値（最後の点検の I1・I2）
    // ============================================================

    /** 選び直しの前と後: 自動で入った値のまま／手で打った値（手で打った値は残す。決めたこと 1 の「今の値のまま」は手入力のため） */
    public static function typedLandAmounts(): array
    {
        return [
            '自動で入った値のまま' => ['', ''],
            '手で打った土地の契約額' => [' data.amountLand = "21000000";', '21000000'],
        ];
    }

    /**
     * I1: 区画が 1 つの土地（自動で区画が選ばれ、土地の契約額に 25,000,000 が入る）から、販売価格の無い区画のある土地に替えて
     * その区画を選ぶと、前の土地の区画の金額を残さない（別の土地の金額が、自動で入った値に見える形で保存されないように）。
     */
    #[DataProvider('typedLandAmounts')]
    public function test_switching_the_land_drops_the_amount_the_previous_lot_filled_in(string $typed, string $expectedLand): void
    {
        $single = $this->landWithCost(['procurement_code' => 'RE-PRC-001', 'property_name' => '区画 1 つの土地']);
        $this->procurementLot($single);
        [$two, , $noPrice] = $this->twoLotLand(['procurement_code' => 'RE-PRC-002', 'property_name' => '区画 2 つの土地'], null);
        $html = $this->htmlOf($this->createUrl());
        $chooseSingle = 'data.contractType = "procurement_land"; data.onTypeChange(); data.procurementId = "' . $single->id . '"; data.onProcurementChange();';

        $before = $this->browserForm($html, 'action="' . route('realestate.contracts.store') . '"', 'contractForm', $chooseSingle . $typed,
            [$this->apiResponse(route('api.realestate.procurement-cost', $single))]);
        $this->assertSame($expectedLand === '' ? '25000000' : $expectedLand, $before['fields']['contract_amount_land'], 'テストの前提: 区画 1 つの土地で土地の契約額が入っていない');

        $switchTo = $chooseSingle . $typed . ' data.procurementId = "' . $two->id . '"; data.onProcurementChange();';
        $responses = [$this->apiResponse(route('api.realestate.procurement-cost', $single)), $this->apiResponse(route('api.realestate.procurement-cost', $two))];

        // 替えた直後（選べる区画が 2 つなので区画はまだ選ばない）
        $switched = $this->browserForm($html, 'action="' . route('realestate.contracts.store') . '"', 'contractForm', $switchTo, $responses);
        $this->assertSame(['', $expectedLand, ''], $this->lotAndAmounts($switched),
            '土地を替えた直後に、前の土地の区画の金額が残っている（か手で打った値が消えた）');

        $after = $this->browserForm($html, 'action="' . route('realestate.contracts.store') . '"', 'contractForm', $switchTo . $this->chooseLot($noPrice), $responses);

        $this->assertSame([(string) $noPrice->id, $expectedLand, ''], $this->lotAndAmounts($after),
            '土地を替えて販売価格の無い区画を選んでも、前の土地の区画の金額が残っている（か手で打った値が消えた）');
    }

    /**
     * I1（編集画面）: 今の区画（販売価格 20,000,000）が入れた値のままの土地の契約額は、販売価格の無い区画を選び直すと空にする。
     * 原価は按分できない土地なので区画の値ではない（保存した値＝手で打った値として残す）。
     */
    public function test_choosing_a_lot_without_a_price_on_the_edit_screen_drops_the_amount_of_the_current_lot(): void
    {
        [$land, $first, $noPrice] = $this->twoLotLand([], null);
        $contract = $this->contractOn($land, $first);

        $form = $this->browserForm($this->htmlOf(route('realestate.contracts.edit', $contract)), $this->editNeedle($contract), 'contractEditForm', $this->chooseLot($noPrice));

        $this->assertSame([(string) $noPrice->id, '', '20000000'], $this->lotAndAmounts($form),
            '販売価格の無い区画を選んでも、今の区画の販売価格が土地の契約額に残っている（か原価が消えた）');
    }

    /**
     * I2・Ruling 14（登録画面）: 「取られている」で断られて戻った画面は、取られた区画を選んだままにせず、
     * その区画が自動で入れた値も残さない（手で打った値は残す）。残った区画を選び直して登録できる。
     */
    #[DataProvider('typedLandAmounts')]
    public function test_a_refused_lot_is_not_kept_on_the_returned_screen(string $typed, string $expectedLand): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $url = $this->createUrl();
        $stale = $this->completed($this->createForm($this->htmlOf($url), $land, $this->chooseLot($first) . $typed), '佐藤');
        $this->assertSame([(string) $first->id, $expectedLand === '' ? '20000000' : $expectedLand, '20000000'], $this->lotAndAmounts($stale), 'テストの前提');
        $this->contractOn($land, $first);   // 開いたあとで、ほかの人が区画 1 の契約を登録した

        $html = $this->landed($this->submit($stale, $url));

        $this->assertErrorItem($html, 'この区画（1号地）は契約「勝山の土地（山田 太郎 様）」に紐づいています。ほかの区画を選んでください。');
        // 戻った画面はそのまま組める（x-model の値が選択肢に無い＝ブラウザでは区画が空に見え、取られた区画の金額と原価が残る形にしない）
        $again = $this->browserForm($html, 'action="' . route('realestate.contracts.store') . '"', 'contractForm');
        $this->assertSame(['', $expectedLand, ''], $this->lotAndAmounts($again), '戻った画面が取られた区画を選んだまま（か、その区画の金額・原価を残している）');

        // 残った区画を選び直して登録できる
        $html = $this->landed($this->submit($this->browserForm($html, 'action="' . route('realestate.contracts.store') . '"', 'contractForm', $this->chooseLot($second)), $url));
        $this->assertFlash($html, 'success', '契約を登録しました。');
        $saved = ReContract::where('procurement_lot_id', $second->id)->sole();
        $this->assertSame([10000000, 10000000], [$saved->contract_amount_land, $saved->cost_amount]);
    }

    /**
     * I2（編集画面・#21 の形）: 区画の無い契約で区画を選び、その区画が取られていて断られて戻った画面は、取られた区画を外し、
     * その区画の金額と原価も外す（「区画なしのまま保存します」の案内が出たまま、取られた区画の金額と原価で保存されないように）。
     * 付け替え（新しく選ぶ区画）が取られていれば今どおり断る（Ruling 15 で refusal() を false にしても）。
     */
    public function test_a_legacy_contract_does_not_keep_a_refused_lot_on_the_returned_screen(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $contract = $this->contractOn($land, null);   // 区画の無い契約（#21）。土地の契約額・原価とも 20,000,000
        $url = route('realestate.contracts.edit', $contract);
        $stale = $this->browserForm($this->htmlOf($url), $this->editNeedle($contract), 'contractEditForm', $this->chooseLot($second));
        $this->assertSame([(string) $second->id, '10000000', '10000000'], $this->lotAndAmounts($stale), 'テストの前提');
        $this->buildingOn($land, $second, 'HS-LOT-001', '区画 2 の建売');   // 開いたあとで、建売が区画 2 を取った

        $html = $this->landed($this->submit($stale, $url));

        $this->assertErrorItem($html, 'この区画（2号地）は建売物件「HS-LOT-001 区画 2 の建売」に紐づいています。ほかの区画を選んでください。');
        $this->assertSame([null, 20000000, 20000000], [$contract->fresh()->procurement_lot_id, $contract->fresh()->contract_amount_land, $contract->fresh()->cost_amount]);
        $again = $this->browserForm($html, $this->editNeedle($contract), 'contractEditForm');
        $this->assertSame(['', '', ''], $this->lotAndAmounts($again), '戻った画面が取られた区画（か、その区画の金額・原価）を残している');
        $this->assertStringContainsString(self::LEGACY_NOTICE, $html);

        // そのまま送っても、取られた区画の金額と原価で「区画なし」のまま保存されない（金額が空で止まる）
        $html = $this->landed($this->submit($again, $url));
        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '契約額（土地）']));
        $this->assertSame([null, 20000000, 20000000], [$contract->fresh()->procurement_lot_id, $contract->fresh()->contract_amount_land, $contract->fresh()->cost_amount]);
    }

    // ============================================================
    // 食い違ったデータ（今の区画にほかのものも付いている。同時の保存でだけ起きる）と付け替え（最後の点検の M1・M2・Ruling 15）
    // ============================================================

    /**
     * M1: 今の区画は、ほかの建売にも付いているときも、編集画面の選択肢に残して選んだまま描く
     * （selectableLots() と同じ。消すと選択欄から今の区画が消え、そのまま保存できない＝Bug #130 の形）。
     */
    public function test_the_edit_screen_keeps_the_current_lot_even_if_it_is_also_taken_elsewhere(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $contract = $this->contractOn($land, $first);
        $this->buildingOn($land, $first);

        $html = $this->htmlOf(route('realestate.contracts.edit', $contract));

        $this->assertSame([$first->id, $second->id], $this->lotOptionIds($html, 'contractEditForm', $land, 'contractEditForm()'), '今の区画が選択肢から消えた');
        $form = $this->browserForm($html, $this->editNeedle($contract), 'contractEditForm');
        $this->assertSame([(string) $first->id, '20000000', '20000000'], $this->lotAndAmounts($form), '今の区画が選ばれていない（か金額が外れた）');
    }

    /**
     * B3（設計書 2026-11-23-lot-link-followups）: 区画の無い土地の契約の編集画面を開いたあとで、区画を足してその契約に
     * つないだ（Bug #146）。区画を選べる画面なので補わず、今どの区画に紐づいているかを文で知らせて断る。
     * 戻った画面ではその区画が選ばれていて、もう一度送ると保存できる。
     */
    public function test_a_stale_contract_edit_tells_which_lot_it_now_has(): void
    {
        $land = $this->landWithCost();
        $contract = $this->contractOn($land, null);
        $url = route('realestate.contracts.edit', $contract);
        $stale = $this->fill($this->browserForm($this->htmlOf($url), $this->editNeedle($contract), 'contractEditForm'), ['memo' => '鍵は 2 本']);

        $detail = route('realestate.procurements.show', $land);
        $this->submit($this->parseForm($this->htmlOf($detail), 'id="lot-create-form"'), $detail)->assertRedirect($detail . '#lot');
        $lot = ReProcurementLot::where('procurement_id', $land->id)->sole();
        $this->assertSame($lot->id, (int) $contract->fresh()->procurement_lot_id, '区画を足しても契約につながっていない');

        $response = $this->submit($stale, $url);
        $response->assertRedirect($url);
        $html = $this->landed($response);

        $this->assertErrorItem($html, strtr(ReContractController::LOT_HELD, [':number' => '1']));
        $this->assertNull($contract->fresh()->memo);

        $again = $this->browserForm($html, $this->editNeedle($contract), 'contractEditForm');
        $this->assertSame([(string) $lot->id, '鍵は 2 本'], [$again['fields']['procurement_lot_id'] ?? null, $again['fields']['memo'] ?? null]);
        $html = $this->landed($this->submit($again, $url));

        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $this->assertSame([$lot->id, '鍵は 2 本'], [(int) $contract->fresh()->procurement_lot_id, $contract->fresh()->memo]);
    }

    /** M2・Ruling 15: 同じ食い違ったデータでも、何も変えない保存は断らない（契約には「この保存で区画を取る」瞬間が無い） */
    public function test_an_unchanged_save_passes_even_if_the_lot_is_also_taken_elsewhere(): void
    {
        [$land, $first] = $this->twoLotLand();
        $contract = $this->contractOn($land, $first);
        $this->buildingOn($land, $first);
        $url = route('realestate.contracts.edit', $contract);

        $form = $this->fill($this->browserForm($this->htmlOf($url), $this->editNeedle($contract), 'contractEditForm'), ['memo' => '鍵は 2 本']);
        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $this->assertSame([$first->id, '鍵は 2 本'], [$contract->fresh()->procurement_lot_id, $contract->fresh()->memo]);
    }

    /** Ruling 15: 付け替える区画が取られていれば、今どおり断る（refusal() の $selfTakes を false にしても、変えた区画は見る） */
    public function test_switching_to_a_taken_lot_is_still_refused(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $contract = $this->contractOn($land, $first);
        $url = route('realestate.contracts.edit', $contract);
        $stale = $this->browserForm($this->htmlOf($url), $this->editNeedle($contract), 'contractEditForm', $this->chooseLot($second));
        $this->buildingOn($land, $second, 'HS-LOT-002', '区画 2 の建売');   // 開いたあとで、建売が区画 2 を取った

        $html = $this->landed($this->submit($stale, $url));

        $this->assertErrorItem($html, 'この区画（2号地）は建売物件「HS-LOT-002 区画 2 の建売」に紐づいています。ほかの区画を選んでください。');
        $this->assertSame($first->id, $contract->fresh()->procurement_lot_id);
    }

    // ============================================================
    // ステータス（設計書 §5.4）
    // ============================================================

    private function register(ReProcurement $land, ReProcurementLot $lot, string $buyerLast): ReContract
    {
        $url = $this->createUrl();
        $html = $this->landed($this->submit($this->completed($this->createForm($this->htmlOf($url), $land, $this->chooseLot($lot)), $buyerLast), $url));
        $this->assertFlash($html, 'success', '契約を登録しました。');

        return ReContract::where('procurement_lot_id', $lot->id)->sole();
    }

    private function destroyFromScreen(ReContract $contract): void
    {
        $show = route('realestate.contracts.show', $contract);
        $form = $this->parseForm($this->htmlOf($show), 'action="' . route('realestate.contracts.destroy', $contract) . '"');
        $this->landed($this->submit($form, $show));
    }

    /** @return array<int, string> 区画のステータス・仕入れ案件のステータス */
    private function statuses(ReProcurement $land, ReProcurementLot ...$lots): array
    {
        return [...array_map(fn (ReProcurementLot $lot) => $lot->fresh()->status->value, $lots), $land->fresh()->status->value];
    }

    public function test_lot_contracts_drive_lot_and_procurement_status(): void
    {
        [$land, $first, $second] = $this->twoLotLand();

        $this->register($land, $first, '山田');
        $this->assertSame(['sold', 'on_sale', 'selling'], $this->statuses($land, $first, $second), '区画 1 つの契約で仕入れ案件が販売済になった');

        $secondContract = $this->register($land, $second, '佐藤');
        $this->assertSame(['sold', 'sold', 'sold'], $this->statuses($land, $first, $second));

        $this->destroyFromScreen($secondContract);
        $this->assertSame(['sold', 'on_sale', 'selling'], $this->statuses($land, $first, $second));

        // 3 区画の土地で、区画 1 の契約を区画 3 へ付け替える
        $three = $this->landWithCost(['procurement_code' => 'RE-PRC-002', 'property_name' => '3 区画の土地']);
        $lots = array_map(fn (int $n) => $this->procurementLot($three, ['lot_number' => $n, 'selling_price' => 10000000]), [1, 2, 3]);
        $contract = $this->register($three, $lots[0], '鈴木');
        $url = route('realestate.contracts.edit', $contract);
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.contracts.update', $contract) . '"', 'contractEditForm', $this->chooseLot($lots[2]));
        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $this->assertSame($lots[2]->id, $contract->fresh()->procurement_lot_id);
        $this->assertSame(['on_sale', 'on_sale', 'sold', 'selling'], $this->statuses($three, ...$lots));
    }

    /**
     * 編集画面で区画を選び直すと、土地の契約額に新しい区画の販売価格・原価に新しい区画の按分が入り、その値で保存される
     * （最後の点検の V2b・V2d: 販売価格の同じ区画どうしの付け替えしか見ておらず、入れ替えを消しても緑だった）。
     */
    public function test_choosing_another_lot_on_the_edit_screen_fills_its_amount_and_cost(): void
    {
        [$land, $first, $second] = $this->twoLotLand();
        $contract = $this->contractOn($land, $first);   // 土地の契約額・原価とも 20,000,000（区画 1 の販売価格と按分）
        $url = route('realestate.contracts.edit', $contract);

        $form = $this->browserForm($this->htmlOf($url), $this->editNeedle($contract), 'contractEditForm', $this->chooseLot($second));
        $this->assertSame([(string) $second->id, '10000000', '10000000'], $this->lotAndAmounts($form), '区画を選び直しても、新しい区画の販売価格・按分が入らない');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $contract->refresh();
        $this->assertSame([$second->id, 10000000, 10000000, 0], [$contract->procurement_lot_id, $contract->contract_amount_land, $contract->cost_amount, $contract->gross_profit]);
    }

    public function test_lotless_lands_keep_todays_status_rules(): void
    {
        $land = $this->landWithCost();
        $url = $this->createUrl();
        $form = $this->createForm($this->htmlOf($url), $land);
        $this->assertArrayNotHasKey('procurement_lot_id', $form['fields'], '区画の無い土地で区画の欄が送られている');

        $html = $this->landed($this->submit($this->completed($this->fill($form, ['contract_amount_land' => '35000000'])), $url));
        $this->assertFlash($html, 'success', '契約を登録しました。');
        $contract = ReContract::sole();
        $this->assertNull($contract->procurement_lot_id);
        $this->assertSame([30000000, 'sold'], [$contract->cost_amount, $land->fresh()->status->value]);

        $this->destroyFromScreen($contract);
        $this->assertSame('selling', $land->fresh()->status->value);
    }

    // ============================================================
    // 契約の詳細（Bug #46。区画が 2 つ以上の土地は按分した額と比べる）
    // ============================================================

    public function test_the_show_screen_compares_with_the_allocated_cost(): void
    {
        [$land, , $second] = $this->twoLotLand();
        $url = $this->createUrl();
        $form = $this->fill($this->completed($this->createForm($this->htmlOf($url), $land, $this->chooseLot($second))), ['cost_amount' => '9000000']);
        $this->landed($this->submit($form, $url));
        $contract = ReContract::sole();

        $show = $this->htmlOf(route('realestate.contracts.show', $contract));

        $this->assertStringContainsString('/ 区画 2号地 132.69㎡（40.13坪） 販売価格 10,000,000円', $show);
        $this->assertSame(1, preg_match('/この区画の按分: 販売価格 10,000,000円 ÷ 全区画の販売価格の合計 30,000,000円\s*<span[^>]*>→ 10,000,000円<\/span>/u', $show), '按分の行が出ていない');
        $this->assertStringContainsString('現在の仕入れ原価: 10,000,000円', $show);
        $this->assertStringContainsString('⚠ 現在の原価と 1,000,000円の差', $show);
        $this->assertStringContainsString('⚠ 契約後に仕入れ案件の原価が 1,000,000円増えています。', $show);
    }

    public function test_allocation_is_unavailable_when_a_lot_has_no_price(): void
    {
        [$land, , $second] = $this->twoLotLand([], null);
        $url = $this->createUrl();

        $form = $this->createForm($this->htmlOf($url), $land, $this->chooseLot($second));
        $this->assertSame([(string) $second->id, '', ''], [$form['fields']['procurement_lot_id'], $form['fields']['contract_amount_land'], $form['fields']['cost_amount']],
            '按分できない区画を選んだのに原価欄（か土地の契約額）が入った');

        $form = $this->fill($this->completed($form), ['contract_amount_land' => '9000000', 'cost_amount' => '8000000']);
        $this->landed($this->submit($form, $url));
        $show = $this->htmlOf(route('realestate.contracts.show', ReContract::sole()));

        $this->assertStringContainsString('この区画の按分: 全区画に販売価格が入っていないため、按分できません', $show);
        $this->assertStringContainsString('現在の仕入れ原価: —', $show);
        $this->assertStringNotContainsString('⚠ 現在の原価と', $show);
        $this->assertStringNotContainsString('⚠ 契約後に仕入れ案件の原価が', $show);
    }

    public function test_one_lot_lands_use_the_full_cost(): void
    {
        $land = $this->landWithCost();
        $lot = $this->procurementLot($land, ['selling_price' => null]);
        $url = $this->createUrl();

        $form = $this->createForm($this->htmlOf($url), $land);
        $this->assertSame([(string) $lot->id, '', '30000000'], [$form['fields']['procurement_lot_id'], $form['fields']['contract_amount_land'], $form['fields']['cost_amount']],
            '区画が 1 つの土地の原価が全額にならない');

        $this->landed($this->submit($this->completed($this->fill($form, ['contract_amount_land' => '35000000'])), $url));
        $show = $this->htmlOf(route('realestate.contracts.show', ReContract::sole()));

        $this->assertStringContainsString('/ 区画 1号地 132.69㎡（40.13坪）</span>', $show);
        $this->assertStringContainsString('現在の仕入れ原価: 30,000,000円', $show);
        $this->assertStringNotContainsString('この区画の按分', $show);
        $this->assertStringNotContainsString('⚠ 現在の原価と', $show);
    }
}
