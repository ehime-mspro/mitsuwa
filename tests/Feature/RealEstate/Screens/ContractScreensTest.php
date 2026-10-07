<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReContract;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use Illuminate\Support\Facades\Lang;

/**
 * 不動産の契約の登録（分譲地。区画の一覧と原価は画面の JS が API から取る）・編集・仲介の成約／不成約を、
 * 描いた画面から送る往復で見る。
 *
 * ⚠ 登録画面は契約の種別で同じ name の欄（担当者・備考）を出し分け、隠した方を `:disabled` で送らない。
 *   browserForm() は `:disabled` を JS の状態で評価して落とす（ブラウザと同じ）。
 */
class ContractScreensTest extends RealEstateScreenTestCase
{
    /** 登録画面で分譲地の契約を選び、分譲地と区画を選ぶ（区画の一覧と原価は JS が API から取る。その応答をそのまま返す） */
    private function subdivisionForm(string $html, ReProject $project, ReProjectLot $lot): array
    {
        $responses = [
            $this->apiResponse(route('api.realestate.project-lots', $project)),
            $this->apiResponse(route('api.realestate.project-lot-cost', $project)),
        ];

        return $this->browserForm($html, 'action="' . route('realestate.contracts.store') . '"', 'contractForm',
            'data.contractType = "subdivision_lot"; data.onTypeChange(); data.projectId = "' . $project->id . '"; data.onProjectChange();
             setImmediate(function () { data.lotId = "' . $lot->id . '"; data.onLotChange(); });', $responses);
    }

    public function test_a_subdivision_contract_is_registered_from_the_screen(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 3, 12000000);
        $this->lot($project, 4, 11000000);
        \App\Models\ReProjectCost::create(['project_id' => $project->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 9000000]);
        $buyer = $this->buyer();
        $url = route('realestate.contracts.create');

        $form = $this->subdivisionForm($this->htmlOf($url), $project, $lot);
        $this->assertSame([(string) $lot->id, '12000000', '4500000', '平井分譲地'],
            [$form['fields']['lot_id'], $form['fields']['contract_amount_land'], $form['fields']['cost_amount'], $form['fields']['property_name']],
            '区画を選んでも販売価格・区画あたりの原価・物件名が入らない');
        // 仲介の欄・建物の欄は :disabled で送らない（同じ name の担当者・備考は分譲地の側が 1 つだけ送られる）
        $this->assertSame([], array_values(array_intersect(['brokerage_selling_price', 'brokerage_fee', 'contract_amount_building', 'tax_amount', 'procurement_id'], array_keys($form['fields']))), '隠れている欄が送られている');
        $form = $this->fill($form, ['buyer_id' => (string) $buyer->id, 'contract_date' => '2026-10-01']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を登録しました。');
        $contract = ReContract::where('lot_id', $lot->id)->firstOrFail();
        $this->assertSame([12000000, 4500000, 7500000], [$contract->contract_amount_land, $contract->cost_amount, $contract->gross_profit]);
        $this->assertSame('sold', $lot->fresh()->status->value);
    }

    public function test_a_procurement_contract_is_edited_from_the_screen(): void
    {
        $procurement = $this->procurement();
        $buyer = $this->buyer();
        $contract = ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_house', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の家', 'procurement_id' => $procurement->id, 'buyer_id' => $buyer->id,
            'contract_amount_land' => 10000000, 'contract_amount_building' => 5000000, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);
        $url = route('realestate.contracts.edit', $contract);

        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.contracts.update', $contract) . '"', 'contractEditForm',
            'data.onBuildingExclInput("6000000");');
        $this->assertSame(['10000000', '6000000', (string) $buyer->id], [$form['fields']['contract_amount_land'], $form['fields']['contract_amount_building'], $form['fields']['buyer_id']]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $contract->refresh();
        $this->assertSame([10000000, 6000000, 7000000], [$contract->contract_amount_land, $contract->contract_amount_building, $contract->gross_profit]);
    }

    // ============================================================
    // 仲介の成約・不成約（詳細画面）
    // ============================================================

    private function listing(string $name = '仲介A', ?int $fee = 300000): ReContract
    {
        return ReContract::create([
            'department' => 'realestate', 'contract_type' => 'brokerage', 'status' => 'listing',
            'property_name' => $name, 'brokerage_fee' => $fee, 'created_by' => $this->user->id,
        ]);
    }

    public function test_a_brokerage_is_closed_from_the_detail_screen(): void
    {
        $contract = $this->listing();
        $url = route('realestate.contracts.show', $contract);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.contracts.close', $contract) . '"');
        $this->assertSame(['PATCH', '300000'], [$form['fields']['_method'], $form['fields']['brokerage_fee']], '成約の小窓に登録時の手数料が入っていない');
        $form = $this->fill($form, ['contract_date' => '2026-10-02', 'buyer_name' => '高橋 花子', 'brokerage_fee' => '330000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '仲介案件を成約にしました。');
        $contract->refresh();
        $this->assertSame(['closed', '2026-10-02', '高橋 花子', 330000, 330000],
            [$contract->status->value, $contract->contract_date->toDateString(), $contract->buyer_name, $contract->brokerage_fee, $contract->gross_profit]);
    }

    public function test_a_brokerage_close_without_a_fee_reopens_the_dialog_with_the_reason(): void
    {
        $contract = $this->listing('仲介B', null);
        $url = route('realestate.contracts.show', $contract);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('realestate.contracts.close', $contract) . '"'), ['brokerage_fee' => '']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '仲介手数料']));
        $this->assertSame(1, preg_match('/showCloseModal: true\b/', $html), '入力エラーで戻ったのに成約の小窓が開いていない');
        $this->assertSame('listing', $contract->fresh()->status->value);
    }

    public function test_a_brokerage_is_marked_lost_from_the_detail_screen(): void
    {
        $contract = $this->listing();
        $url = route('realestate.contracts.show', $contract);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.contracts.lost', $contract) . '"');
        $this->assertSame('PATCH', $form['fields']['_method']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '仲介案件を不成約にしました。');
        $this->assertSame('lost', $contract->fresh()->status->value);
    }

    public function test_a_close_request_without_the_buyer_name_field_does_not_fail(): void
    {
        $contract = $this->listing();

        // 画面の小窓は買主名の欄を必ず送るが、欄の無い送信（手で組んだもの）で 500 にならない
        $response = $this->actingAs($this->user)->patch(route('realestate.contracts.close', $contract), ['contract_date' => '2026-10-02', 'brokerage_fee' => '300000']);

        $response->assertRedirect(route('realestate.contracts.show', $contract));
        $contract->refresh();
        $this->assertSame(['closed', null], [$contract->status->value, $contract->buyer_name]);
    }

    // ============================================================
    // 入力エラーで戻ったとき、分譲地の区画が選ばれたまま（R1）
    // ============================================================

    public function test_a_subdivision_contract_keeps_its_lot_after_an_input_error(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 3, 12000000);
        $buyer = $this->buyer();
        $url = route('realestate.contracts.create');
        $form = $this->subdivisionForm($this->htmlOf($url), $project, $lot);

        // 買主を選び忘れて送る
        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '買主']));

        // 戻った画面をそのまま送ると、区画は選ばれたまま（分譲地を選び直さなくてよい）
        $again = $this->browserForm($html, 'action="' . route('realestate.contracts.store') . '"', 'contractForm');
        $this->assertSame([(string) $project->id, (string) $lot->id, '12000000'],
            [$again['fields']['project_id'], $again['fields']['lot_id'], $again['fields']['contract_amount_land']], '戻った画面で区画が選ばれていない');
        $again = $this->fill($again, ['buyer_id' => (string) $buyer->id]);
        $html = $this->landed($this->submit($again, $url));

        $this->assertFlash($html, 'success', '契約を登録しました。');
        $this->assertSame($lot->id, ReContract::firstOrFail()->lot_id);
    }

    public function test_an_edited_subdivision_contract_keeps_its_sold_lot(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 3, 12000000, 'sold');
        $this->lot($project, 4, 11000000);
        $contract = ReContract::create([
            'department' => 'realestate', 'contract_type' => 'subdivision_lot', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '平井分譲地', 'project_id' => $project->id, 'lot_id' => $lot->id, 'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 12000000, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);
        $url = route('realestate.contracts.edit', $contract);

        // 今の区画は販売済みでも選択肢に残り、そのまま保存できる
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.contracts.update', $contract) . '"', 'contractEditForm', 'data.amountLand = "12500000";');
        $this->assertSame((string) $lot->id, $form['fields']['lot_id']);
        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $this->assertSame([$lot->id, 12500000], [$contract->fresh()->lot_id, $contract->fresh()->contract_amount_land]);
        $this->assertSame('sold', $lot->fresh()->status->value);
    }

    public function test_an_edited_subdivision_contract_keeps_the_new_lot_after_an_input_error(): void
    {
        $old = $this->project();
        $new = $this->project(['project_code' => 'RE-PRJ-002', 'project_name' => '北条分譲地']);
        $oldLot = $this->lot($old, 1, 12000000, 'sold');
        $newLot = $this->lot($new, 7, 9000000);
        $contract = ReContract::create([
            'department' => 'realestate', 'contract_type' => 'subdivision_lot', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '平井分譲地', 'project_id' => $old->id, 'lot_id' => $oldLot->id, 'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 12000000, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);
        $url = route('realestate.contracts.edit', $contract);
        $needle = 'action="' . route('realestate.contracts.update', $contract) . '"';
        $responses = [
            $this->apiResponse(route('api.realestate.project-lots', $new)),
            $this->apiResponse(route('api.realestate.project-lot-cost', $new)),
        ];
        $form = $this->browserForm($this->htmlOf($url), $needle, 'contractEditForm',
            'data.projectId = "' . $new->id . '"; data.onProjectChange(); setImmediate(function () { data.lotId = "' . $newLot->id . '"; });', $responses);

        // 分譲地を変えたあと、契約日を消して送る
        $html = $this->landed($this->submit($this->fill($form, ['contract_date' => '']), $url));
        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '契約日']));

        $again = $this->browserForm($html, $needle, 'contractEditForm');
        $this->assertSame([(string) $new->id, (string) $newLot->id], [$again['fields']['project_id'], $again['fields']['lot_id']], '戻った画面で新しい区画が選ばれていない');
        $html = $this->landed($this->submit($this->fill($again, ['contract_date' => '2026-09-01']), $url));

        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $this->assertSame($newLot->id, $contract->fresh()->lot_id);
        $this->assertSame(['on_sale', 'sold'], [$oldLot->fresh()->status->value, $newLot->fresh()->status->value]);
    }

    // ============================================================
    // 入力エラーの項目名が画面のラベルと同じ和名で出る（Bug #110）
    // ⚠ 規則を変数で組むので、以前の走査（リテラルの validate([...]) だけ）には見えなかった。英字の「cost amount」などが出ていた
    // ============================================================

    public function test_a_procurement_contract_names_its_missing_procurement_and_cost_in_japanese(): void
    {
        $url = route('realestate.contracts.create');
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.contracts.store') . '"', 'contractForm',
            'data.contractType = "procurement_land"; data.onTypeChange();');
        $this->assertSame(['', ''], [$form['fields']['procurement_id'], $form['fields']['cost_amount']], '仕入れ案件・原価が空のまま送られていない');

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '仕入れ案件']));
        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '原価']));
    }

    public function test_a_subdivision_contract_names_its_missing_lot_in_japanese(): void
    {
        $project = $this->project();
        $this->lot($project, 3, 12000000);
        $url = route('realestate.contracts.create');
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.contracts.store') . '"', 'contractForm',
            'data.contractType = "subdivision_lot"; data.onTypeChange(); data.projectId = "' . $project->id . '"; data.onProjectChange();',
            [$this->apiResponse(route('api.realestate.project-lots', $project)), $this->apiResponse(route('api.realestate.project-lot-cost', $project))]);
        $this->assertSame('', $form['fields']['lot_id'], '区画を選ばずに送れていない');

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '区画']));
    }

    public function test_a_brokerage_names_its_selling_price_and_address_as_on_the_screen(): void
    {
        $url = route('realestate.contracts.create');
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.contracts.store') . '"', 'contractForm',
            'data.contractType = "brokerage"; data.onTypeChange(); data.propertyName = "仲介C"; data.addressVal = "' . str_repeat('あ', 301) . '";');
        $form = $this->fill($form, ['brokerage_selling_price' => 'abc']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.integer', ['attribute' => '販売金額']));
        // 画面のラベルは「所在地」。全体の和名（住所）は変えずに、この画面だけ呼び出しの第 3 引数で上書きする
        $this->assertErrorItem($html, trans('validation.max.string', ['attribute' => '所在地', 'max' => 300]));
        $this->assertStringNotContainsString(e(trans('validation.max.string', ['attribute' => '住所', 'max' => 300])), $html);
        $this->assertSame('住所', Lang::get('validation.attributes.address'), '全体の和名を「所在地」に書き換えると、住所のラベルの画面が壊れる');
    }

    public function test_an_edited_brokerage_names_its_address_as_on_the_screen(): void
    {
        $contract = $this->listing('仲介D');
        $url = route('realestate.contracts.edit', $contract);
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.contracts.update', $contract) . '"', 'contractEditForm');
        $form = $this->fill($form, ['address' => str_repeat('あ', 301)]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.max.string', ['attribute' => '所在地', 'max' => 300]));
    }

    public function test_an_edit_sending_an_unknown_contract_type_names_it_in_japanese(): void
    {
        // 種別は編集画面の hidden で送る（画面からは変えられない）。手で書き換えた送信で項目名を見る
        $contract = $this->listing('仲介E');
        $url = route('realestate.contracts.edit', $contract);
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.contracts.update', $contract) . '"', 'contractEditForm');
        $this->assertSame('brokerage', $form['fields']['contract_type']);
        $form = $this->fill($form, ['contract_type' => 'nope']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.in', ['attribute' => '契約種別']));
    }

    public function test_a_brokerage_close_names_its_contract_date_as_on_the_dialog(): void
    {
        $contract = $this->listing('仲介F');
        $url = route('realestate.contracts.show', $contract);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('realestate.contracts.close', $contract) . '"'), ['contract_date' => '']);

        $html = $this->landed($this->submit($form, $url));

        // 小窓のラベルは「成約日」（全体の和名は「契約日」）
        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '成約日']));
        $this->assertSame('契約日', Lang::get('validation.attributes.contract_date'));
    }
}
