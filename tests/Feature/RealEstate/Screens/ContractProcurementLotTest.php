<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\LotStatus;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use App\Models\ReProcurementLot;

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
        $detail = route('realestate.procurements.show', $land);

        // あとから区画を 1 つ足す（契約があるので成約で作られる）
        $this->landed($this->submit($this->parseForm($this->htmlOf($detail), 'id="lot-create-form"'), $detail));
        $lot = ReProcurementLot::sole();
        $this->assertSame(LotStatus::Sold, $lot->status);

        $url = route('realestate.contracts.edit', $contract);
        $needle = 'action="' . route('realestate.contracts.update', $contract) . '"';
        $edit = $this->htmlOf($url);
        $form = $this->browserForm($edit, $needle, 'contractEditForm');
        $this->assertSame(['', (string) $land->id], [$form['fields']['procurement_lot_id'], $form['fields']['procurement_id']], '区画が勝手に選ばれた');
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
